<?php
/**
 * ZHR_Parse -- "I worked 7:30 to 4 yesterday and 8 to noon today" (typed or dictated) or a photo of a time sheet, a punch
 * card or a note -> proposed stretches of work the person then sees and adds with one press. NOTHING IS SAVED HERE:
 * this class never touches a table. Every proposal is checked by ZHR_Time::check_entry and attributed by
 * ZHR_Attribution before anyone can add it, and is checked and attributed again by the server when they do.
 *
 * The words and the photo go through the platform's one Ai router (ZDZ_Core_LLM::ask): words as `extract`, a photo as
 * `read_document` (Hours is registered there as a caller that reads documents). The router codes every text part with
 * its own customer-detail coder (ZDZ_Private::for( 'llm' )), so it is given the person's PLAIN words; its answer's notes,
 * names and "unclear" lines come back in the router's codes and are decoded here for the writer only. The instructions
 * are marked as the business's own text (system_company), so a customer named like a word in them never turns that word
 * into a code. Which model reads, and what it may cost, is the business's (the router's model slots and budgets); nothing
 * here names a model.
 *
 * The model only transcribes. It is not told who is asking, so it can never fill a name in: it copies the name written
 * or said beside each stretch, or gives none.
 *
 * A HEIC photo is turned into JPEG on the site when Imagick reads HEIC (the router takes JPEG, PNG, GIF and WebP only);
 * otherwise it is refused in words. Most phones never send HEIC: the app draws each photo into a JPEG first.
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_Parse {

	const CALLER    = 'hours';   // this app's row in ZDZ_Core_LLM::CALLERS
	const TIMEOUT_S = 45;
	const MAX_TEXT  = 4000;
	const MAX_IMAGE = 6000000;   // bytes of base64

	/** What the router takes as a picture. */
	const ROUTER_IMAGES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/** Is the platform's Ai router here? */
	public static function router_ready() {
		return class_exists( 'ZDZ_Core_LLM' ) && method_exists( 'ZDZ_Core_LLM', 'ask' );
	}

	/** Can words and photos be read: the router is here and the business has chosen a model for it. */
	public static function ready() {
		if ( ! self::router_ready() ) {
			return false;
		}
		if ( method_exists( 'ZDZ_Core_LLM', 'models' ) ) {
			$m = ZDZ_Core_LLM::models();
			return '' !== (string) ( $m['primary'] ?? '' );
		}
		return true;
	}

	/** "ready", or what it needs, in plain words for the admin page and the app's settings. Never a key. */
	public static function ready_label() {
		if ( ! self::router_ready() ) {
			return __( 'not available: this site has no Ai router', 'zorderz' );
		}
		return self::ready() ? __( 'ready', 'zorderz' ) : __( 'needs an Ai model: an admin sets one on the Ai usage page', 'zorderz' );
	}

	public static function heic_refused() {
		return __( "That photo is in a format I can't read yet. Share it as a JPEG, or take a screenshot of it.", 'zorderz' );
	}

	/** Can this site turn a phone's HEIC photo into a JPEG? (Imagick, built with HEIC.) */
	public static function heic_readable() {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}
		try {
			$f = array_map( 'strtoupper', (array) Imagick::queryFormats( 'HEI*' ) );
		} catch ( Throwable $e ) {
			return false;
		}
		return in_array( 'HEIC', $f, true ) || in_array( 'HEIF', $f, true );
	}

	/** HEIC bytes -> JPEG bytes (at most 1800 px, quality 85, turned upright, no metadata), or null. */
	public static function heic_to_jpeg( $bytes ) {
		if ( ! is_string( $bytes ) || '' === $bytes || ! self::heic_readable() ) {
			return null;
		}
		try {
			$im = new Imagick();
			$im->readImageBlob( $bytes );
			$im->setFirstIterator();
			if ( method_exists( $im, 'autoOrient' ) ) {
				$im->autoOrient();
			} elseif ( method_exists( $im, 'getImageOrientation' ) ) {
				// an older Imagick: turn it upright by hand (the three turns a phone writes), then say it is upright
				$o    = (int) $im->getImageOrientation();
				$turn = array( 3 => 180, 6 => 90, 8 => -90 );
				if ( isset( $turn[ $o ] ) ) {
					$im->rotateImage( 'black', $turn[ $o ] );
				}
				if ( method_exists( $im, 'setImageOrientation' ) ) {
					$im->setImageOrientation( 1 );
				}
			}
			$w = (int) $im->getImageWidth();
			$h = (int) $im->getImageHeight();
			if ( $w < 1 || $h < 1 ) {
				return null;
			}
			$k = min( 1, 1800 / max( $w, $h ) );
			if ( $k < 1 ) {
				$im->resizeImage( (int) round( $w * $k ), (int) round( $h * $k ), Imagick::FILTER_LANCZOS, 1 );
			}
			$im->setImageFormat( 'jpeg' );
			$im->setImageCompressionQuality( 85 );
			$im->stripImage();   // no metadata leaves the site (a phone's photo can carry where it was taken)
			$out = $im->getImageBlob();
			$im->clear();
			return is_string( $out ) && strlen( $out ) > 100 ? $out : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/** A photo as the router takes it: JPEG, PNG, GIF or WebP as it is; HEIC turned into JPEG, or refused in words. */
	public static function image_for_router( array $image ) {
		$mime = strtolower( (string) ( $image['mime'] ?? '' ) );
		if ( in_array( $mime, self::ROUTER_IMAGES, true ) ) {
			return array( 'mime' => $mime, 'data' => (string) $image['data'] );
		}
		if ( 'image/heic' === $mime || 'image/heif' === $mime ) {
			$jpeg = self::heic_to_jpeg( base64_decode( (string) $image['data'], true ) );
			if ( null !== $jpeg ) {
				return array( 'mime' => 'image/jpeg', 'data' => base64_encode( $jpeg ) );
			}
			return new WP_Error( 'image_format', self::heic_refused(), array( 'status' => 415 ) );
		}
		return new WP_Error( 'image', __( 'That file is not a photo Hours can read (JPEG, PNG, WebP or HEIC).', 'zorderz' ), array( 'status' => 400 ) );
	}

	/**
	 * The answer's shape, for structured outputs, with no unions: a missing time is "", a missing length 0, no name "".
	 * ZHR_Time::proposals_from_model() reads each as "not given". No descriptions: every string in a schema goes past the
	 * router's coder.
	 */
	public static function answer_schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'entries', 'unclear' ),
			'properties'           => array(
				'entries' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'date', 'start', 'end', 'minutes', 'note', 'name' ),
						'properties'           => array(
							'date'    => array( 'type' => 'string' ),
							'start'   => array( 'type' => 'string' ),
							'end'     => array( 'type' => 'string' ),
							'minutes' => array( 'type' => 'integer' ),
							'note'    => array( 'type' => 'string' ),
							'name'    => array( 'type' => 'string' ),
						),
					),
				),
				'unclear' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			),
		);
	}

	/** A router error said in Hours' own plain words; the log gets its code only. */
	public static function router_error( WP_Error $e ) {
		$code = (string) $e->get_error_code();
		error_log( 'Hours: the Ai router answered ' . preg_replace( '/[^a-z0-9_]/', '', $code ) ); // phpcs:ignore -- the code only, never the text
		$exact = ' ' . __( 'You can still add exact times.', 'zorderz' );
		switch ( $code ) {
			case 'zdz_llm_no_key':
			case 'zdz_llm_no_workspace':
			case 'zdz_llm_model':
			case 'zdz_llm_prices':
			case 'zdz_llm_caller':
				return new WP_Error( 'reader_setup', __( 'Reading words and photos is not set up on this site yet: an admin sets up the Ai in the Zorderz settings.', 'zorderz' ) . $exact, array( 'status' => 503 ) );
			case 'zdz_llm_month':
			case 'zdz_llm_budget':
			case 'zdz_llm_money':
				return new WP_Error( 'reader_month', __( "The Ai has used this month's budget.", 'zorderz' ) . $exact, array( 'status' => 503 ) );
			case 'zdz_llm_auth':
			case 'zdz_llm_wrong_workspace':
				return new WP_Error( 'reader_key', __( 'The Ai key was not accepted. An admin checks it in the Zorderz settings.', 'zorderz' ) . $exact, array( 'status' => 503 ) );
			case 'zdz_llm_kiosk':
				return new WP_Error( 'reader_kiosk', __( 'Reading words and photos is not available on the shared device.', 'zorderz' ), array( 'status' => 403 ) );
			case 'zdz_llm_unreachable':
			case 'zdz_llm_network':
			case 'zdz_llm_time':
			case 'zdz_llm_overloaded':
			case 'zdz_llm_rate_limited':
				return new WP_Error( 'reader_time', __( 'The reader did not answer in time. Try again, or add exact times.', 'zorderz' ), array( 'status' => 504 ) );
			case 'zdz_llm_bad_json':
			case 'zdz_llm_empty':
				return new WP_Error( 'reader_parse', __( 'The reader did not give back hours I could use. Try saying it another way, or add exact times.', 'zorderz' ), array( 'status' => 422 ) );
		}
		return new WP_Error( 'reader_failed', __( 'The reader could not read that just now. Try again, or add exact times.', 'zorderz' ), array( 'status' => 502 ) );
	}

	/**
	 * The instructions: the business's own text (no person's name in them: the model is never told who is asking).
	 * Codes are described in words, with no ⟦ ⟧ in the text: the router defuses any it is given.
	 */
	public static function prompt( $today, $settings ) {
		$tz    = ZHR_Time::tz()->getName();
		$dow   = ( new DateTimeImmutable( $today . ' 12:00:00', ZHR_Time::tz() ) )->format( 'l' );
		$names = array( 1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		$std   = array();
		for ( $d = 1; $d <= 7; $d++ ) {
			$row   = $settings['days'][ $d ] ?? array();
			$std[] = $names[ $d ] . ': ' . ( empty( $row['on'] ) ? 'day off' : $row['start'] . '-' . $row['end'] );
		}
		$week = array();
		$ws   = ZHR_Time::week_start( $today, (int) ( $settings['week_start'] ?? 1 ) );
		for ( $i = -7; $i < 7; $i++ ) {
			$d      = ZHR_Time::add_days( $ws, $i );
			$week[] = $d . ' ' . ( new DateTimeImmutable( $d . ' 12:00:00' ) )->format( 'D' );
		}
		$week_s = implode( ', ', $week );
		$std_l  = ZHR_Time::has_standard( $settings ) ? "\nThe standard hours: " . implode( '; ', $std ) . '.' : '';
		return <<<PROMPT
You read an account of hours worked and turn it into time entries. It may be typed or dictated words, or a photo of a
time sheet, a punch card, a whiteboard or a handwritten note.

Today is {$today} ({$dow}), time zone {$tz}.
Dates of last week and this week: {$week_s}.{$std_l}

Return ONE JSON object and nothing else (no markdown fences, no commentary):
{
  "entries": [ { "date": "YYYY-MM-DD", "start": "HH:MM" or "", "end": "HH:MM" or "", "minutes": a whole number or 0, "note": string, "name": string } ],
  "unclear": [ string ]
}

Rules:
- One entry per continuous stretch of work. "7 to 11, lunch, 11:30 to 4" is two entries: 07:00-11:00 and 11:30-16:00.
- Times are 24-hour HH:MM. Read "7:30 to 4" as 07:30-16:00 and "8 to noon" as 08:00-12:00: a working day's afternoon hour
  without am/pm is pm when it would otherwise end before it starts. "Midnight" is 00:00.
- Resolve relative days against today: "yesterday", "Monday" (the most recent Monday on or before today),
  "last Thursday" (the Thursday of last week). Never a date after today.
- When only a length is given ("8 hours yesterday", "worked 6.5 on Tuesday"), set start and end to "" and minutes to
  the length in minutes (6.5 hours = 390). When a start and a length are given, give start and minutes, and end "".
- "note" is what the work was, in a few words, taken from the words or the sheet, or "" when nothing says. Never put a
  customer's name, address or phone number in a note: write "customer job" instead.
- Private details in the words have already been replaced with codes: a code between double square brackets (one
  bracket character on each side), starting with c: or e:. They stand for a customer, an address or a phone number.
  Copy such a code exactly, character for character, brackets included, where it belongs in a note ("work at" and then
  the code). Never guess what a code stands for, change one, or write one that was not in the words.
- A gap in the day ("7 to 11:30, lunch, 12 to 4") is a break: two entries, never one.
- "name" is the name of the person whose hours the stretch is, copied exactly as it is written or said (initials stay
  initials), or "" when no name is written or said for that stretch. Words about the speaker's own hours ("I worked 7
  to 3") have no name. Never guess a name, never carry one over from another row, and never shorten or correct one.
- A photo with several people's hours on it: give every row you can read, each with the name written beside it.
- Never invent hours that were not said or written. Anything you could not read or were unsure about goes in "unclear"
  as a short plain sentence ("Thursday's end time is smudged.").
- At most 31 entries.
PROMPT;
	}

	/**
	 * Read words or a photo. $plain: the person's own words, NOT coded (the router codes them). $image: ['mime', 'data'
	 * (base64)] or null. Returns ['entries' => proposals (unchecked, unattributed; each with the name read beside it,
	 * decoded for the writer), 'unclear' => [...], 'kind' => 'words'|'photo', 'model' => '…'] or WP_Error.
	 */
	public static function read( $plain, $image, $settings, $today ) {
		if ( ! self::router_ready() ) {
			return new WP_Error( 'reader_setup', __( 'Reading words and photos is not available on this site. You can still add exact times.', 'zorderz' ), array( 'status' => 503 ) );
		}
		$text = trim( (string) $plain );
		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT );
		}
		$content = array( array( 'type' => 'text', 'text' => ( '' !== $text ? "The words:\n" . $text : 'A photo of hours worked.' ) . "\nReturn the JSON object now." ) );
		$role    = 'extract';
		if ( $image ) {
			$img = self::image_for_router( $image );
			if ( is_wp_error( $img ) ) {
				return $img;
			}
			$content[] = array( 'type' => 'image', 'media_type' => $img['mime'], 'data' => $img['data'] );
			$role      = 'read_document';
		}
		$r = ZDZ_Core_LLM::ask( $role, array(
			'system'         => self::prompt( $today, $settings ),
			// the instructions are the business's own text: the one-word name rules leave them alone. The person's words
			// are never marked: they are their own and may name customers.
			'system_company' => true,
			'messages'       => array( array( 'role' => 'user', 'content' => $content ) ),
			'json_schema'    => self::answer_schema(),
		), array(
			'caller'   => self::CALLER,
			'user_id'  => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'deadline' => time() + self::TIMEOUT_S,
		) );
		if ( is_wp_error( $r ) ) {
			return self::router_error( $r );
		}
		if ( ! is_array( $r['json'] ?? null ) ) {
			return self::router_error( new WP_Error( 'zdz_llm_bad_json', '' ) );   // never an empty success
		}
		$plain_of = function ( $s ) {
			$s = (string) $s;
			if ( class_exists( 'ZDZ_Private' ) ) {
				$s = ZDZ_Private::for( 'llm' )->decode( $s, true ); // for the writer, who wrote it
			}
			return $s;
		};
		$json = $r['json'];
		foreach ( (array) ( $json['entries'] ?? array() ) as $i => $e ) {
			if ( is_array( $e ) ) {
				$json['entries'][ $i ]['note'] = $plain_of( $e['note'] ?? '' );
				$json['entries'][ $i ]['name'] = $plain_of( $e['name'] ?? '' );
			}
		}
		$entries = ZHR_Time::proposals_from_model( $json, $settings, $today );
		$unclear = array();
		foreach ( array_slice( (array) ( $json['unclear'] ?? array() ), 0, 8 ) as $x ) {
			if ( is_string( $x ) && '' !== trim( $x ) ) {
				$unclear[] = ZHR_Time::clean_note( $plain_of( $x ) );
			}
		}
		return array( 'entries' => $entries, 'unclear' => $unclear, 'kind' => $image ? 'photo' : 'words', 'model' => (string) ( $r['model'] ?? '' ) );
	}

	/** A data: URL from the phone -> ['mime','data'] or WP_Error. Only images, at most ~4.5 MB. */
	public static function image_from_data_url( $url ) {
		$url = (string) $url;
		if ( ! preg_match( '#^data:(image/(?:jpeg|png|webp|heic|heif));base64,([A-Za-z0-9+/=\s]+)$#', $url, $m ) ) {
			return new WP_Error( 'image', __( 'That file is not a photo Hours can read (JPEG, PNG, WebP or HEIC).', 'zorderz' ), array( 'status' => 400 ) );
		}
		$data = preg_replace( '/\s+/', '', $m[2] );
		if ( strlen( $data ) > self::MAX_IMAGE ) {
			return new WP_Error( 'image', __( 'That photo is too large. Take it again a little further away.', 'zorderz' ), array( 'status' => 413 ) );
		}
		return array( 'mime' => $m[1], 'data' => $data );
	}
}
