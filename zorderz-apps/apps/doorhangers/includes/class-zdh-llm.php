<?php
/**
 * The map reader: a vision model reads screenshots, paper route maps and photos of them, and answers one JSON object.
 *
 * Two roads, in this order:
 *   1. The platform's AI router (ZDZ_Core_LLM::ask, role read_document, caller `doorhangers`) when the theme has it:
 *      the router owns the provider, the model, the spending limits and the privacy coding.
 *   2. Otherwise the platform's Poe gateway: the key is the one Poe key (Zorderz -> Settings) and the model is the
 *      `vision` slot of ZDZ_Model_Registry. The body is built by ZDZ_Core_Poe::build_body() and never carries web
 *      search: a map image is never sent with web search on.
 * No key box and no model name live in this app.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_LLM {

	const ENDPOINT = 'https://api.poe.com/v1/chat/completions';
	const CALLER   = 'doorhangers';

	public static function system_prompt( $region = '' ) {
		$where = '' !== trim( (string) $region ) ? ' working in ' . trim( (string) $region ) : '';
		return <<<PROMPT
You are a mapping analyst for a door-hanger distribution team$where.
A salesperson walks residential streets hanging flyers on front doors, then marks the covered area on a phone screenshot of a map app (usually Apple Maps or Google Maps) with a hand-drawn orange/red line. The marking is either:
  (a) a closed loop drawn around an area — every house inside the loop was covered, or
  (b) a line traced along the streets that were walked — the houses along those streets were covered.
Sometimes there is also a dropped pin or a search label such as "Oak Hollow Dr & Maple Ave" naming the starting intersection.
The image can also be a SCAN OF A PRINTED PAPER MAP from an older route, where the walked streets were marked with highlighter pens (often yellow and blue) and sometimes pen notes. Different colours can mean different things; when the team's key is supplied below, follow it.
It can also be a PHONE PHOTO OF SUCH A PRINTED MAP: taken at an angle, the sheet curled or held in a hand, a table or floor around it, often a printed title at the top (a short name such as "North 2"). Read it the same way. Every x/y position is a position in the PHOTO as you see it (0-1000 of its width and height), not on the paper.

Read the screenshot(s) carefully and return ONE JSON object and nothing else (no markdown fences, no commentary) with exactly these keys:
{
  "map_app": "Apple Maps" | "Google Maps" | "other",
  "marking_style": "loop" | "path" | "none",
  "pin_label": string | null,
  "city_guess": string | null,
  "neighborhood_guess": string | null,
  "zip_guess": string | null,
  "zip_confidence": "high" | "medium" | "low",
  "covered_streets": [
    { "name": string, "coverage": "full" | "partial", "from_cross_street": string | null, "to_cross_street": string | null, "notes": string | null }
  ],
  "cross_streets": [ string ],
  "other_visible_streets": [ string ],
  "landmarks": [ string ],
  "screen_readings": { "clock": string | null, "temperature_f": number | null, "aqi": number | null, "other_text": [ string ] },
  "handwritten_notes": { "quantity": number | null, "hanger_type": string | null, "date": string | null, "text": [ string ] },
  "source_type": "phone screenshot" | "paper scan" | "photo of paper map" | "other",
  "sheet_label": string | null,
  "marks": [ { "color": string, "meaning": string, "streets": [ string ] } ],
  "marking_color": "orange" | "red" | "yellow" | "pink" | "green" | "blue" | "other",
  "north_up": true | false,
  "pin": { "label": string, "x": number, "y": number } | null,
  "anchors": [ { "street_a": string, "street_b": string, "x": number, "y": number } ],
  "confidence": "high" | "medium" | "low",
  "reasoning": string
}

Rules:
- covered_streets lists ONLY streets whose houses were covered: streets the traced line runs along, or streets inside / on the boundary of the loop. Streets merely visible elsewhere go in other_visible_streets.
- coverage is "full" when the marking runs along the street's whole length or the loop encloses all of it; "partial" when only a portion is covered — then name the two cross streets that bound the covered portion (from_cross_street / to_cross_street). Use the visible screen edge as a bound only if nothing else is available and say so in notes.
- cross_streets: every street named in a from/to bound, plus the streets in the pin label.
- Copy street names exactly as printed on the map, in normal capitalization ("Oak Hollow Dr", "Via Example"). Keep the suffix as printed (Dr, Ave, St, Ln, Ct, Rd, Pl, Way…). Do not invent streets you cannot read.
- screen_readings come from the phone UI if present: the status-bar clock, and Apple Maps' weather chip (temperature in °F and AQI number).
- handwritten_notes: any text the salesperson wrote or typed on the image (a quantity like "90", a hanger type, a date). Null when absent.
- Be thorough with covered_streets: for a loop, list EVERY street that has houses inside or on the boundary of the loop — including the main through-street the loop runs along (houses on the inside edge of a boundary street count). For a traced path, list every street the line runs along, even short stretches.
- from_cross_street / to_cross_street must be streets that physically intersect that street on this map. Never use a street from elsewhere on the screen as a bound.
- Georeferencing: "anchors" are 3 to 6 intersections of two named streets that you can point at precisely, spread across the whole screenshot (near different corners if possible). x is the horizontal position (0 = left edge, 1000 = right edge) and y the vertical position (0 = top edge, 1000 = bottom edge) of the exact point where the two street centerlines cross. Only use intersections whose two names are fully legible. "pin" is the tip of the dropped pin (same coordinate system) with its label, or null. "north_up" is true when the map is oriented with north at the top (the default; the compass shows N pointing up).
- marking_color is the colour of the hand-drawn marking (for a paper map: the colour whose marks mean the houses were delivered).
- sheet_label: the title printed or written at the top of a paper map (for example "North 2"); null when there is none.
- For a PHOTO of a paper map give 5 to 8 anchors spread over the whole printed map (near its four corners and its middle), because the photo is seen at an angle. Only intersections where both street names are printed and legible.
- For a paper map, put a date written on the sheet into handwritten_notes.date as YYYY-MM-DD when you can (null when there is none).
- marks: one entry per kind of marking you can see (each highlighter colour, crosses, circles, arrows), with its meaning from the team's key (or "unknown" when there is no key or it is not in the key) and the streets it is on. Empty for a plain screenshot with one marking.
- For a paper scan or a photo of one, covered_streets lists ONLY the streets whose marks mean delivered by the key (when no key is supplied, every highlighted street). Streets with other marks (skipped, not done, notes) go in other_visible_streets and in marks — never in covered_streets.
- If the salesperson supplied hints (ZIP, city), trust them over your own guess and use them to disambiguate street names.
- Be concise in reasoning (one or two sentences).
PROMPT;
	}

	/** Is the platform's AI router there to take the call? */
	public static function router() {
		return class_exists( 'ZDZ_Core_LLM' ) && method_exists( 'ZDZ_Core_LLM', 'ask' );
	}

	/** The platform's one Poe key ('' on an older theme or when none is set). */
	public static function key() {
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_poe_api_key' ) ) {
			return (string) ZDZ_Core_Settings::get_poe_api_key();
		}
		return '';
	}

	public static function ready() {
		return self::router() || ( self::key() !== '' && self::model() !== '' );
	}

	/** The vision model on the Poe road: the registry's `vision` slot. '' when none is configured (never a name of our own). */
	public static function model() {
		if ( self::router() ) {
			return '';
		}
		if ( class_exists( 'ZDZ_Model_Registry' ) && method_exists( 'ZDZ_Model_Registry', 'model_for' ) ) {
			return (string) ZDZ_Model_Registry::model_for( 'vision' );
		}
		return '';
	}

	/** The request body on the Poe road: the gateway's own body for the model, never web search. */
	public static function policed( $body ) {
		unset( $body['web_search'], $body['tools'] );
		$body['model'] = self::model();
		if ( class_exists( 'ZDZ_Core_Poe' ) && method_exists( 'ZDZ_Core_Poe', 'build_body' ) ) {
			$gw   = new ZDZ_Core_Poe( self::key(), $body['model'] );
			$body = $gw->build_body( (array) $body['messages'], (float) ( $body['temperature'] ?? 0.1 ), array(), (string) $body['model'] );
		}
		unset( $body['web_search'], $body['tools'] );
		return $body;
	}

	/** The region the reader is told about: "County, State" from the app's default area, or ''. */
	public static function region() {
		$a = function_exists( 'zdh_area_defaults' ) ? zdh_area_defaults() : array( 'county' => '', 'state' => '' );
		return implode( ', ', array_filter( array( trim( (string) $a['county'] ), trim( (string) $a['state'] ) ) ) );
	}

	/** The words that go with the pictures: how many, the person's hints, the team's paper key. */
	public static function user_text( $count, $hints ) {
		$s          = function_exists( 'zdh_get_settings' ) ? zdh_get_settings() : array();
		$hint_lines = array();
		foreach ( array( 'zip' => 'ZIP code', 'city' => 'City', 'date' => 'Date of drop', 'time' => 'Time', 'notes' => 'Notes', 'delivered_by' => 'Delivered by' ) as $k => $label ) {
			if ( ! empty( $hints[ $k ] ) ) {
				$hint_lines[] = $label . ': ' . $hints[ $k ];
			}
		}
		$text  = (int) $count . ' picture(s) attached.';
		$text .= $hint_lines ? "\nHints from the salesperson:\n- " . implode( "\n- ", $hint_lines ) : "\nNo hints were supplied.";
		$key_txt = trim( (string) ( $s['paper_key'] ?? '' ) );
		if ( '' !== $key_txt ) {
			$text .= "\nThe team's key for marks on their paper route maps (applies when an image is a scan or photo of a paper map):\n" . $key_txt;
		}
		return $text . "\nReturn the JSON object now.";
	}

	/**
	 * Analyze one or more screenshots. $images: [ [ 'mime' => 'image/jpeg', 'data' => base64 ], ... ]
	 * $tick: called while waiting for the reader on the Poe road (from curl's progress callback, about once a second),
	 * so the caller can keep its own connection alive or leave a heartbeat. Ignored when WordPress is not using curl.
	 * Returns array (parsed JSON) or WP_Error.
	 */
	public static function analyze( $images, $hints, $tick = null ) {
		$text    = self::user_text( count( $images ), $hints );
		$t_start = microtime( true );
		if ( self::router() ) {
			$parts = array( array( 'type' => 'text', 'text' => $text ) );
			foreach ( $images as $img ) {
				$parts[] = array( 'type' => 'image', 'media_type' => (string) $img['mime'], 'data' => (string) $img['data'] );
			}
			try {
				$r = ZDZ_Core_LLM::ask( 'read_document', array(
					'system'     => self::system_prompt( self::region() ),
					'messages'   => array( array( 'role' => 'user', 'content' => $parts ) ),
					'max_output' => 6000,
				), array(
					'caller'   => self::CALLER,
					'user_id'  => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
					'deadline' => time() + 150,
				) );
			} catch ( Throwable $e ) {
				return new WP_Error( 'zdh_ai_threw', __( 'The AI could not read the picture.', 'zorderz' ) );
			}
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$raw = (string) ( $r['text'] ?? '' );
			if ( class_exists( 'ZDZ_Private' ) && method_exists( 'ZDZ_Private', 'for' ) && false !== strpos( $raw, '⟦' ) ) {
				$coder = ZDZ_Private::for( 'llm' );
				if ( is_object( $coder ) && method_exists( $coder, 'decode' ) ) {
					$raw = (string) $coder->decode( $raw, true );
				}
			}
			$parsed = is_array( $r['json'] ?? null ) && false === strpos( wp_json_encode( $r['json'] ), '⟦' ) ? $r['json'] : self::extract_json( $raw );
			if ( ! is_array( $parsed ) ) {
				return new WP_Error( 'zdh_ai_parse', __( 'The AI did not answer in the expected shape.', 'zorderz' ) );
			}
			$parsed['_model'] = (string) ( $r['model'] ?? '' );
			$parsed['_usage'] = $r['usage'] ?? null;
			$parsed['_secs']  = round( microtime( true ) - $t_start, 1 );
			return self::normalize( $parsed );
		}

		$key   = self::key();
		$model = self::model();
		if ( ! $key ) {
			return new WP_Error( 'no_key', __( 'No Poe key is set (Zorderz -> Settings).', 'zorderz' ) );
		}
		if ( '' === $model ) {
			return new WP_Error( 'no_model', __( 'No vision model is set (Zorderz -> Settings, the vision slot).', 'zorderz' ) );
		}
		$content = array( array( 'type' => 'text', 'text' => $text ) );
		foreach ( $images as $img ) {
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array( 'url' => 'data:' . $img['mime'] . ';base64,' . $img['data'] ),
			);
		}

		$body = self::policed( array(
			'model'       => $model,
			'temperature' => 0.1,
			'messages'    => array(
				array( 'role' => 'system', 'content' => self::system_prompt( self::region() ) ),
				array( 'role' => 'user', 'content' => $content ),
			),
		) );

		$hook = null;
		if ( is_callable( $tick ) && defined( 'CURLOPT_XFERINFOFUNCTION' ) ) {
			$hook = function ( $handle ) use ( $tick ) {
				curl_setopt( $handle, CURLOPT_NOPROGRESS, false );
				curl_setopt( $handle, CURLOPT_XFERINFOFUNCTION, function () use ( $tick ) {
					return false === call_user_func( $tick ) ? 1 : 0; // 0 = carry on; non-zero stops the transfer (a cancelled job)
				} );
			};
			add_action( 'http_api_curl', $hook );
		}
		$resp = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 150,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );
		if ( $hook ) {
			remove_action( 'http_api_curl', $hook );
		}
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'poe_http', sprintf( __( 'Could not reach Poe after %1$d s: %2$s', 'zorderz' ), (int) round( microtime( true ) - $t_start ), $resp->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$raw  = wp_remote_retrieve_body( $resp );
		$json = json_decode( $raw, true );
		if ( 200 !== $code ) {
			$msg = is_array( $json ) && isset( $json['error']['message'] ) ? $json['error']['message'] : substr( wp_strip_all_tags( $raw ), 0, 300 );
			return new WP_Error( 'poe_' . $code, sprintf( __( 'Poe returned HTTP %1$d: %2$s', 'zorderz' ), (int) $code, $msg ) );
		}
		$text_out = '';
		if ( isset( $json['choices'][0]['message']['content'] ) ) {
			$c = $json['choices'][0]['message']['content'];
			if ( is_array( $c ) ) { // some providers return content parts
				foreach ( $c as $part ) {
					if ( isset( $part['text'] ) ) {
						$text_out .= $part['text'];
					}
				}
			} else {
				$text_out = (string) $c;
			}
		}
		$parsed = self::extract_json( $text_out );
		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'poe_parse', __( 'The model did not return valid JSON. Raw reply: ', 'zorderz' ) . substr( $text_out, 0, 400 ) );
		}
		$parsed['_model'] = $model;
		$parsed['_usage'] = $json['usage'] ?? null;
		$parsed['_secs']  = round( microtime( true ) - $t_start, 1 );
		return self::normalize( $parsed );
	}

	/** Pull the first {...} block out of a reply that may include fences or prose. */
	public static function extract_json( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );
		$j    = json_decode( $text, true );
		if ( is_array( $j ) ) {
			return $j;
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}
		$j = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		return is_array( $j ) ? $j : null;
	}

	/** Make sure every key exists with the right shape so the front-end never has to guess. */
	private static function normalize( $p ) {
		$out = array(
			'map_app'               => (string) ( $p['map_app'] ?? '' ),
			'marking_style'         => (string) ( $p['marking_style'] ?? '' ),
			'pin_label'             => isset( $p['pin_label'] ) ? (string) $p['pin_label'] : null,
			'city_guess'            => isset( $p['city_guess'] ) ? (string) $p['city_guess'] : null,
			'neighborhood_guess'    => isset( $p['neighborhood_guess'] ) ? (string) $p['neighborhood_guess'] : null,
			'zip_guess'             => isset( $p['zip_guess'] ) ? preg_replace( '/[^0-9]/', '', (string) $p['zip_guess'] ) : null,
			'zip_confidence'        => (string) ( $p['zip_confidence'] ?? 'low' ),
			'covered_streets'       => array(),
			'cross_streets'         => array(),
			'other_visible_streets' => array(),
			'landmarks'             => array(),
			'screen_readings'       => array( 'clock' => null, 'temperature_f' => null, 'aqi' => null, 'other_text' => array() ),
			'handwritten_notes'     => array( 'quantity' => null, 'hanger_type' => null, 'date' => null, 'text' => array() ),
			'source_type'           => (string) ( $p['source_type'] ?? '' ),
			// the title printed on a paper sheet ("North 2"), at most 60 characters
			'sheet_label'           => isset( $p['sheet_label'] ) && is_scalar( $p['sheet_label'] ) && '' !== trim( (string) $p['sheet_label'] ) ? mb_substr( sanitize_text_field( (string) $p['sheet_label'] ), 0, 60 ) : null,
			'marks'                 => array(),
			'marking_color'         => strtolower( (string) ( $p['marking_color'] ?? 'orange' ) ),
			'north_up'              => ! isset( $p['north_up'] ) || (bool) $p['north_up'],
			'pin'                   => null,
			'anchors'               => array(),
			'confidence'            => (string) ( $p['confidence'] ?? 'low' ),
			'reasoning'             => (string) ( $p['reasoning'] ?? '' ),
			'_model'                => $p['_model'] ?? '',
			'_usage'                => $p['_usage'] ?? null,
			'_secs'                 => isset( $p['_secs'] ) ? (float) $p['_secs'] : null, // how long the reader took
		);
		$xy = function ( $v ) {
			return is_array( $v ) && isset( $v['x'], $v['y'] ) && is_numeric( $v['x'] ) && is_numeric( $v['y'] ) && $v['x'] >= 0 && $v['x'] <= 1000 && $v['y'] >= 0 && $v['y'] <= 1000;
		};
		if ( ! empty( $p['pin'] ) && $xy( $p['pin'] ) ) {
			$out['pin'] = array( 'label' => trim( (string) ( $p['pin']['label'] ?? '' ) ), 'x' => (float) $p['pin']['x'], 'y' => (float) $p['pin']['y'] );
		}
		foreach ( (array) ( $p['anchors'] ?? array() ) as $an ) {
			if ( $xy( $an ) && ! empty( $an['street_a'] ) && ! empty( $an['street_b'] ) ) {
				$out['anchors'][] = array( 'a' => trim( (string) $an['street_a'] ), 'b' => trim( (string) $an['street_b'] ), 'x' => (float) $an['x'], 'y' => (float) $an['y'] );
			}
		}
		foreach ( (array) ( $p['covered_streets'] ?? array() ) as $cs ) {
			if ( is_string( $cs ) ) {
				$cs = array( 'name' => $cs );
			}
			if ( empty( $cs['name'] ) ) {
				continue;
			}
			$out['covered_streets'][] = array(
				'name'              => trim( (string) $cs['name'] ),
				'coverage'          => ( isset( $cs['coverage'] ) && 'partial' === strtolower( (string) $cs['coverage'] ) ) ? 'partial' : 'full',
				'from_cross_street' => ! empty( $cs['from_cross_street'] ) ? trim( (string) $cs['from_cross_street'] ) : null,
				'to_cross_street'   => ! empty( $cs['to_cross_street'] ) ? trim( (string) $cs['to_cross_street'] ) : null,
				'notes'             => ! empty( $cs['notes'] ) ? trim( (string) $cs['notes'] ) : null,
			);
		}
		foreach ( (array) ( $p['marks'] ?? array() ) as $mk ) {
			if ( ! is_array( $mk ) || ( empty( $mk['color'] ) && empty( $mk['meaning'] ) ) ) {
				continue;
			}
			$out['marks'][] = array(
				'color'   => substr( trim( (string) ( $mk['color'] ?? '' ) ), 0, 40 ),
				'meaning' => substr( trim( (string) ( $mk['meaning'] ?? '' ) ), 0, 200 ),
				'streets' => array_slice( array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) ( $mk['streets'] ?? array() ) ) ) ) ), 0, 60 ),
			);
		}
		foreach ( array( 'cross_streets', 'other_visible_streets', 'landmarks' ) as $k ) {
			foreach ( (array) ( $p[ $k ] ?? array() ) as $v ) {
				if ( is_string( $v ) && trim( $v ) !== '' ) {
					$out[ $k ][] = trim( $v );
				}
			}
		}
		$sr = is_array( $p['screen_readings'] ?? null ) ? $p['screen_readings'] : array();
		$out['screen_readings']['clock']         = isset( $sr['clock'] ) ? (string) $sr['clock'] : null;
		$out['screen_readings']['temperature_f'] = isset( $sr['temperature_f'] ) && is_numeric( $sr['temperature_f'] ) ? (float) $sr['temperature_f'] : null;
		$out['screen_readings']['aqi']           = isset( $sr['aqi'] ) && is_numeric( $sr['aqi'] ) ? (int) $sr['aqi'] : null;
		$out['screen_readings']['other_text']    = array_values( array_filter( array_map( 'strval', (array) ( $sr['other_text'] ?? array() ) ) ) );
		$hn = is_array( $p['handwritten_notes'] ?? null ) ? $p['handwritten_notes'] : array();
		$out['handwritten_notes']['quantity']    = isset( $hn['quantity'] ) && is_numeric( $hn['quantity'] ) ? (int) $hn['quantity'] : null;
		$out['handwritten_notes']['hanger_type'] = ! empty( $hn['hanger_type'] ) ? (string) $hn['hanger_type'] : null;
		$out['handwritten_notes']['date']        = ! empty( $hn['date'] ) ? (string) $hn['date'] : null;
		$out['handwritten_notes']['text']        = array_values( array_filter( array_map( 'strval', (array) ( $hn['text'] ?? array() ) ) ) );
		return $out;
	}

	/** Cheap text-only round trip used by the settings "connection check". */
	public static function ping() {
		if ( self::router() ) {
			try {
				$r = ZDZ_Core_LLM::ask( 'classify', array(
					'system'   => 'Reply with the single word OK.',
					'messages' => array( array( 'role' => 'user', 'content' => 'Reply with the single word OK.' ) ),
				), array( 'caller' => self::CALLER, 'user_id' => (int) get_current_user_id(), 'deadline' => time() + 40 ) );
			} catch ( Throwable $e ) {
				return new WP_Error( 'zdh_ai_threw', __( 'The AI router could not answer.', 'zorderz' ) );
			}
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			return array( 'model' => (string) ( $r['model'] ?? 'router' ), 'reply' => (string) ( $r['text'] ?? '' ) );
		}
		$key = self::key();
		if ( ! $key ) {
			return new WP_Error( 'no_key', __( 'No Poe key is set (Zorderz -> Settings).', 'zorderz' ) );
		}
		if ( '' === self::model() ) {
			return new WP_Error( 'no_model', __( 'No vision model is set (Zorderz -> Settings, the vision slot).', 'zorderz' ) );
		}
		$resp = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 40,
			'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( self::policed( array(
				'model'    => self::model(),
				'messages' => array( array( 'role' => 'user', 'content' => 'Reply with the single word OK.' ) ),
			) ) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'poe_' . $code, 'HTTP ' . $code . ': ' . ( $json['error']['message'] ?? substr( wp_remote_retrieve_body( $resp ), 0, 200 ) ) );
		}
		return array( 'model' => self::model(), 'reply' => $json['choices'][0]['message']['content'] ?? '' );
	}
}
