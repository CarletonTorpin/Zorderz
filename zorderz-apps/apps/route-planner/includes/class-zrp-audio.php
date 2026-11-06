<?php
/**
 * ZRP_Audio -- a survey drive's recording, read by a transcription service the platform connects and placed on the
 * drive's GPS path.
 *
 * A salesperson records the drive like a voice memo with the app open (or adds a voice-memo file after it); the
 * recording is time-synced to the GPS path afterwards. "Scratch that" / "delete that" is read in context: the reader
 * decides how much a correction takes back.
 *
 *   The phone    records the drive in parts (MediaRecorder; a pause or the phone dropping the microphone ends a part,
 *                the next starts a new one), each part knowing the wall-clock moment it started. Or a voice-memo
 *                file picked after the drive, with its start time read from the file and nudged by the person.
 *   Upload       POST audio (a part), POST audio/<id>/chunk?seq=N (raw bytes, in order, <= 2 MB each, resumable),
 *                POST audio/<id>/done. Each chunk is sealed as it arrives (libsodium secretbox, a key derived from the
 *                site's SECURE_AUTH salt, never the database) into a randomly named file under uploads/zrp-audio/
 *                (denied to the web as well): a copied file or database alone is noise.
 *   The reader   a registry verb (Charts' `charts.survey` by default; filter `zrp_audio_verb`) with three actions: submit
 *                (kind transcribe: the recording's one-time URL, size, type and the survey's words as a hint; kind
 *                read: the sentences' streets), result (job, offset, forget). It fetches the recording ONCE through
 *                GET audio-fetch/<id>?k=<token> -- a random token, kept only as its hash, good for a day, for that one
 *                part -- transcribes it, and the file is deleted here. The sentences are kept sealed in the row only
 *                until the read comes back. Without a reader registered, recordings wait.
 *   Placing      a sentence's moment = the part's start + the nudge + its time in the recording; its position is
 *                the drive's track at that moment (between two fixes, in proportion; none more than 90 s from a fix:
 *                "street not known yet"); then the same snap, scrub and tags as a spoken note, source 'audio'. The
 *                read (the reader's model, or rules) says what a correction took back: those words are kept as
 *                'retracted' for an Undo, never counted. Gate codes are sealed (ZRP_Codes) before anything is kept.
 *   Pipeline     uploading -> ready -> submitted (transcribing) -> reading -> done | failed. Advanced on "done", when
 *                the page asks (GET audio), and by WordPress's cron every 5 minutes, always AS THE RECORDING'S OWNER
 *                (the registry names who asks: the reader files the job under them).
 *
 * Who sees a recording: its owner and the admin tier -- that it exists and how far along it is, never its words.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRP_Audio {

	const CHUNK_MAX  = 2097152;     // 2 MB a chunk
	const PART_MAX   = 419430400;   // 400 MB a part
	const TOKEN_S    = 86400;       // the reader's link: one download, within a day (the reader may have a queue)
	const VERB       = 'charts.survey';
	const FIX_GAP_S  = 90;          // a sentence further than this from any GPS fix is not placed
	const HOOK       = 'zrp_audio_tick';
	const DIR        = 'zrp-audio';
	const STATUSES   = array( 'uploading', 'ready', 'submitted', 'reading', 'done', 'failed' );

	public static function t() { global $wpdb; return $wpdb->prefix . 'zrp_audio'; }

	public static function create_tables() {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			drive_id bigint(20) unsigned NOT NULL DEFAULT 0,
			part int NOT NULL DEFAULT 0,
			kind varchar(6) NOT NULL DEFAULT 'rec',
			mime varchar(60) NOT NULL DEFAULT '',
			started_ms bigint(20) NOT NULL DEFAULT 0,
			offset_ms int NOT NULL DEFAULT 0,
			bytes bigint(20) NOT NULL DEFAULT 0,
			got bigint(20) NOT NULL DEFAULT 0,
			chunks int NOT NULL DEFAULT 0,
			file varchar(64) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL DEFAULT 'uploading',
			job varchar(24) NOT NULL DEFAULT '',
			read_job varchar(24) NOT NULL DEFAULT '',
			token_hash varchar(64) NOT NULL DEFAULT '',
			token_exp datetime NULL,
			work longtext NULL,
			error varchar(160) NOT NULL DEFAULT '',
			how varchar(8) NOT NULL DEFAULT '',
			creation_time varchar(40) NOT NULL DEFAULT '',
			duration_s int NULL,
			notes_made int NOT NULL DEFAULT 0,
			tries int NOT NULL DEFAULT 0,
			lock_until datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (id),
			KEY user_drive (user_id, drive_id),
			KEY status (status)
		) $c;" );
	}

	/* ------------------------------------------------------------------ sealing */

	public static function ready() {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'wp_salt' );
	}

	private static function key() {
		return sodium_crypto_generichash( 'zrp-audio-v1|' . wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * One sealed record: 4-byte length, nonce, box. A piece of a recording carries its sequence number inside the box
	 * ("S" + 4 bytes), so a piece written twice is read once and pieces out of order are refused.
	 */
	public static function seal_record( $plain, $seq = null ) {
		$plain = null === $seq ? (string) $plain : 'S' . pack( 'N', (int) $seq ) . (string) $plain;
		$key   = self::key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, $key );
		sodium_memzero( $key );
		return pack( 'N', strlen( $box ) ) . $box;
	}

	/** Open the records of a file one at a time; $emit(bytes) per record. False on a damaged file or changed salts. */
	public static function open_file( $path, $emit ) {
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) {
			return false;
		}
		$key  = self::key();
		$ok   = true;
		$next = 0;
		while ( ! feof( $fh ) ) {
			$len = fread( $fh, 4 );
			if ( '' === $len || false === $len ) {
				break;
			}
			if ( 4 !== strlen( $len ) ) { $ok = false; break; }
			$n   = unpack( 'N', $len )[1];
			if ( $n <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || $n > self::CHUNK_MAX + 1024 ) { $ok = false; break; }
			$box = '';
			while ( strlen( $box ) < $n && ! feof( $fh ) ) {
				$box .= fread( $fh, $n - strlen( $box ) );
			}
			$plain = strlen( $box ) === $n ? sodium_crypto_secretbox_open( substr( $box, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $box, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key ) : false;
			if ( false === $plain || 'S' !== substr( $plain, 0, 1 ) || strlen( $plain ) < 5 ) { $ok = false; break; }
			$seq = unpack( 'N', substr( $plain, 1, 4 ) )[1];
			if ( $seq < $next ) { continue; }               // the same piece written twice: once
			if ( $seq > $next ) { $ok = false; break; }     // a piece missing
			$next++;
			$emit( substr( $plain, 5 ) );
		}
		fclose( $fh );
		sodium_memzero( $key );
		return $ok;
	}

	public static function seal_json( $v ) {
		return base64_encode( self::seal_record( wp_json_encode( $v ) ) );
	}

	public static function open_json( $s ) {
		$raw = base64_decode( (string) $s, true );
		if ( false === $raw || strlen( $raw ) < 4 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$box   = substr( $raw, 4 );
		$key   = self::key();
		$plain = sodium_crypto_secretbox_open( substr( $box, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $box, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
		sodium_memzero( $key );
		$v = false === $plain ? null : json_decode( $plain, true );
		return is_array( $v ) ? $v : null;
	}

	/* ------------------------------------------------------------------ files */

	public static function dir() {
		$u = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array( 'basedir' => WP_CONTENT_DIR . '/uploads' );
		$d = rtrim( (string) $u['basedir'], '/' ) . '/' . self::DIR;
		if ( ! is_dir( $d ) ) {
			@mkdir( $d, 0750, true );
		}
		if ( is_dir( $d ) && ! file_exists( $d . '/.htaccess' ) ) {
			@file_put_contents( $d . '/.htaccess', "Require all denied\nDeny from all\n" );
			@file_put_contents( $d . '/index.php', "<?php // Silence.\n" );
			@file_put_contents( $d . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
		}
		return $d;
	}

	public static function path( array $a ) {
		return preg_match( '/^[a-f0-9]{32}\.bin$/', (string) $a['file'] ) ? self::dir() . '/' . $a['file'] : '';
	}

	public static function drop_file( array $a ) {
		$p = self::path( $a );
		if ( '' !== $p && file_exists( $p ) ) {
			@unlink( $p );
		}
	}

	/* ------------------------------------------------------------------ rows */

	public static function get( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t() ($wpdb->prefix and a fixed name); values are placeholders
		return is_array( $r ) ? $r : null;
	}

	public static function set( $id, array $f ) {
		global $wpdb;
		$f['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		return false !== $wpdb->update( self::t(), $f, array( 'id' => (int) $id ) );
	}

	/** A recording as its owner (or the admin tier) sees it: how far along, never its words. */
	public static function out( array $a ) {
		$say = array( 'uploading' => __( 'Uploading', 'zorderz' ), 'ready' => __( 'Waiting for the transcription service', 'zorderz' ), 'submitted' => __( 'The transcription service is listening', 'zorderz' ),
			'reading' => __( 'The transcription service is reading it', 'zorderz' ), 'done' => __( 'Done', 'zorderz' ), 'failed' => __( 'Could not be read', 'zorderz' ) );
		return array(
			'id' => (int) $a['id'], 'drive_id' => (int) $a['drive_id'], 'part' => (int) $a['part'], 'kind' => (string) $a['kind'],
			'status' => (string) $a['status'], 'says' => $say[ $a['status'] ] ?? $a['status'], 'error' => (string) $a['error'],
			'bytes' => (int) $a['bytes'], 'chunks' => (int) $a['chunks'], 'got' => (int) $a['got'], 'notes' => (int) $a['notes_made'],
			'how' => (string) $a['how'], 'started_ms' => (int) $a['started_ms'], 'offset_ms' => (int) $a['offset_ms'],
			'duration_s' => null === $a['duration_s'] ? null : (int) $a['duration_s'], 'at' => (string) $a['created_at'],
		);
	}

	public static function mine( $uid, $limit = 30 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d', (int) $uid, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t() ($wpdb->prefix and a fixed name); values are placeholders
	}

	/* ------------------------------------------------------------------ REST */

	private static function err( $code, $msg, $status = 400 ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	private static function own( $id ) {
		$a = self::get( (int) $id );
		if ( ! $a ) {
			return self::err( 'not_found', 'That recording was not found.', 404 );
		}
		if ( (int) $a['user_id'] !== get_current_user_id() ) {
			return self::err( 'forbidden', 'Only the person who recorded it sends it.', 403 );
		}
		return $a;
	}

	/** POST audio {drive_id, part, kind: rec|memo, mime, started_ms, bytes, offset_ms}: a part; the same part again resumes. */
	public static function rest_create( WP_REST_Request $req ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return self::err( 'no_sodium', 'This site cannot seal recordings (libsodium is missing), so it will not keep them.', 500 );
		}
		$uid   = get_current_user_id();
		$drive = ZRP_DB::get_drive( (int) $req->get_param( 'drive_id' ) );
		if ( ! $drive || (int) $drive['user_id'] !== $uid ) {
			return self::err( 'no_drive', 'A recording belongs to one of your own survey drives.', 403 );
		}
		$kind  = 'memo' === $req->get_param( 'kind' ) ? 'memo' : 'rec';
		$part  = max( 0, min( 999, (int) $req->get_param( 'part' ) ) );
		$bytes = (int) $req->get_param( 'bytes' );
		$start = (int) $req->get_param( 'started_ms' );
		if ( $bytes <= 0 || $bytes > self::PART_MAX ) {
			return self::err( 'too_big', 'That recording is too large (400 MB at most a part).' );
		}
		if ( ( $start < 1.0e12 || $start > 4.0e12 ) && ! ( 'memo' === $kind && 0 === $start ) ) { // a memo may leave it to the file's own time
			return self::err( 'no_start', 'The recording has no start time.' );
		}
		$have = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE user_id = %d AND drive_id = %d AND kind = %s AND part = %d', $uid, (int) $drive['id'], $kind, $part ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t() ($wpdb->prefix and a fixed name); values are placeholders
		if ( is_array( $have ) ) {
			if ( 'uploading' === $have['status'] && (int) $have['bytes'] === $bytes ) {
				return rest_ensure_response( self::out( $have ) ); // the phone resumes from `chunks`
			}
			if ( 'uploading' !== $have['status'] ) {
				return rest_ensure_response( self::out( $have ) ); // already sent
			}
			self::drop_file( $have );
			$wpdb->delete( self::t(), array( 'id' => (int) $have['id'] ) ); // a different file for the same part: start again
		}
		$mime = substr( preg_replace( '#[^a-z0-9/.+;=-]#i', '', (string) $req->get_param( 'mime' ) ), 0, 60 );
		$ok = $wpdb->insert( self::t(), array(
			'user_id' => $uid, 'drive_id' => (int) $drive['id'], 'part' => $part, 'kind' => $kind, 'mime' => $mime,
			'started_ms' => $start, 'offset_ms' => max( -21600000, min( 21600000, (int) $req->get_param( 'offset_ms' ) ) ),
			'bytes' => $bytes, 'file' => bin2hex( random_bytes( 16 ) ) . '.bin', 'status' => 'uploading',
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		) );
		if ( ! $ok ) {
			return self::err( 'db', 'The recording could not be started.', 500 );
		}
		$row = self::get( (int) $wpdb->insert_id );
		return $row ? rest_ensure_response( self::out( $row ) ) : self::err( 'db', 'The recording could not be started.', 500 );
	}

	/** POST audio/<id>/chunk?seq=N with the raw bytes. In order; a chunk sent twice is taken once. */
	public static function rest_chunk( WP_REST_Request $req ) {
		$a = self::own( $req['id'] );
		if ( is_wp_error( $a ) ) {
			return $a;
		}
		if ( 'uploading' !== $a['status'] ) {
			return rest_ensure_response( self::out( $a ) );
		}
		$seq = (int) $req->get_param( 'seq' );
		if ( $seq < (int) $a['chunks'] ) {
			return rest_ensure_response( self::out( $a ) );
		}
		if ( $seq > (int) $a['chunks'] ) {
			return self::err( 'out_of_order', 'Send chunk ' . (int) $a['chunks'] . ' next.', 409 );
		}
		$body = (string) $req->get_body();
		if ( '' === $body || strlen( $body ) > self::CHUNK_MAX ) {
			return self::err( 'bad_chunk', 'A piece of the recording was empty or too large.' );
		}
		if ( (int) $a['got'] + strlen( $body ) > (int) $a['bytes'] ) {
			return self::err( 'too_long', 'The recording is longer than it said.' );
		}
		// claim the piece first: only the request that
		// moves `chunks` on from $seq writes; the other answers with the row as it stands
		global $wpdb;
		$len = strlen( $body );
		$won = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET chunks = chunks + 1, got = got + %d, updated_at = %s WHERE id = %d AND chunks = %d AND status = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t() ($wpdb->prefix and a fixed name); values are placeholders
			$len, gmdate( 'Y-m-d H:i:s' ), (int) $a['id'], $seq, 'uploading' ) );
		if ( 1 !== (int) $won ) {
			$now = self::get( (int) $a['id'] );
			return $now ? rest_ensure_response( self::out( $now ) ) : self::err( 'not_found', 'That recording was not found.', 404 );
		}
		$p = self::path( $a );
		if ( '' === $p || false === @file_put_contents( $p, self::seal_record( $body, $seq ), FILE_APPEND | LOCK_EX ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET chunks = chunks - 1, got = got - %d WHERE id = %d AND chunks = %d', $len, (int) $a['id'], $seq + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t() ($wpdb->prefix and a fixed name); values are placeholders
			return self::err( 'disk', 'The recording could not be saved.', 500 );
		}
		@chmod( $p, 0640 );
		$now = self::get( (int) $a['id'] );
		return $now ? rest_ensure_response( self::out( $now ) ) : self::err( 'not_found', 'That recording was not found.', 404 );
	}

	/** POST audio/<id>/done: all of it is here; the reader is asked at once. */
	public static function rest_done( WP_REST_Request $req ) {
		$a = self::own( $req['id'] );
		if ( is_wp_error( $a ) ) {
			return $a;
		}
		if ( 'uploading' === $a['status'] ) {
			if ( (int) $a['got'] !== (int) $a['bytes'] ) {
				return self::err( 'incomplete', 'Not all of the recording has arrived (' . (int) $a['got'] . ' of ' . (int) $a['bytes'] . ' bytes).', 409 );
			}
			self::set( (int) $a['id'], array( 'status' => 'ready' ) );
			$a = self::get( (int) $a['id'] );
			$a = self::advance( $a );
		}
		return rest_ensure_response( self::out( $a ) );
	}

	/** GET audio: this person's recordings, and one step forward for those waiting on the reader (two at most a call). */
	public static function rest_list( WP_REST_Request $req ) {
		$uid   = get_current_user_id();
		$rows  = self::mine( $uid, 30 );
		$moved = 0;
		foreach ( $rows as &$a ) {
			if ( $moved < 2 && in_array( $a['status'], array( 'ready', 'submitted', 'reading' ), true ) && strtotime( (string) ( $a['updated_at'] ?: $a['created_at'] ) . ' UTC' ) < time() - 20 ) {
				$a = self::advance( $a );
				$moved++;
			}
		}
		unset( $a );
		return rest_ensure_response( array( 'audio' => array_map( array( __CLASS__, 'out' ), $rows ) ) );
	}

	/** POST audio/<id> {op: retry | forget}: the owner or the admin tier. */
	public static function rest_act( WP_REST_Request $req ) {
		$a  = self::get( (int) $req['id'] );
		$me = get_current_user_id();
		if ( ! $a ) {
			return self::err( 'not_found', 'That recording was not found.', 404 );
		}
		if ( (int) $a['user_id'] !== $me && ! zrp_user_is_admin( $me ) ) {
			return self::err( 'forbidden', 'That is someone else\'s recording.', 403 );
		}
		$act = (string) $req->get_param( 'op' );
		if ( 'retry' === $act && 'failed' === $a['status'] ) {
			$back = '' !== (string) $a['work'] ? 'reading' : ( file_exists( self::path( $a ) ) ? 'ready' : '' );
			if ( '' === $back ) {
				return self::err( 'gone', 'The recording is no longer on the site; record or upload it again.' );
			}
			self::set( (int) $a['id'], array( 'status' => $back, 'error' => '', 'tries' => 0, 'read_job' => '' ) );
		} elseif ( 'forget' === $act ) {
			self::drop_file( $a );
			global $wpdb;
			$wpdb->delete( self::t(), array( 'id' => (int) $a['id'] ) );
			return rest_ensure_response( array( 'id' => (int) $a['id'], 'forgotten' => true ) );
		} else {
			return self::err( 'bad_action', 'Retry or forget.' );
		}
		$now = self::get( (int) $a['id'] );
		return $now ? rest_ensure_response( self::out( $now ) ) : self::err( 'not_found', 'That recording was not found.', 404 );
	}

	/**
	 * GET audio-fetch/<id>?k=<token>: the reader takes the recording. No WordPress session (the reader has none): the
	 * token is the key, compared to its hash, for this one part, until it expires. Anything else: a bare 404.
	 */
	public static function rest_fetch( WP_REST_Request $req ) {
		$a = self::get( (int) $req['id'] );
		$k = (string) $req->get_param( 'k' );
		if ( ! $a || 'submitted' !== $a['status'] || '' === (string) $a['token_hash'] || ! preg_match( '/^[a-f0-9]{64}$/', $k )
			|| ! hash_equals( (string) $a['token_hash'], hash( 'sha256', $k ) ) || strtotime( (string) $a['token_exp'] . ' UTC' ) < time() ) {
			return new WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$p = self::path( $a );
		if ( '' === $p || ! file_exists( $p ) ) {
			return new WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
		}
		// one download (the token rides in the query string, so it may sit in a server log): the token is
		// spent before a byte goes; a download that fails is asked for again with a new one (advance(), 'download')
		global $wpdb;
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . " SET token_hash = '' WHERE id = %d AND token_hash = %s", (int) $a['id'], (string) $a['token_hash'] ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t() ($wpdb->prefix and a fixed name); values are placeholders
			return new WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
		}
		if ( defined( 'ZRP_TESTING' ) ) {
			$out = '';
			$ok  = self::open_file( $p, function ( $b ) use ( &$out ) { $out .= $b; } );
			return $ok ? array( 'bytes' => $out, 'mime' => $a['mime'] ) : new WP_Error( 'damaged', 'Damaged.', array( 'status' => 500 ) );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . ( preg_match( '#^audio/[a-z0-9.+-]+#i', (string) $a['mime'], $m ) ? $m[0] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . (int) $a['bytes'] );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="drive.bin"' );
		self::open_file( $p, function ( $b ) { echo $b; flush(); } ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw audio bytes sent as a download (audio/* or octet-stream, nosniff, attachment), not HTML
		exit;
	}

	/* ------------------------------------------------------------------ the pipeline */

	/** The words the survey listens for, as a hint for the transcription and the reader's model. */
	public static function vocab_words() {
		$out = array();
		foreach ( ZRP_Vocab::vocabulary() as $v ) {
			$out[] = $v['label'];
		}
		return array_slice( $out, 0, 40 );
	}

	/** The reader's read verb: Charts' `charts.survey`, or the one a site names (filter `zrp_audio_verb`). */
	public static function reader_verb() {
		$v = function_exists( 'apply_filters' ) ? (string) apply_filters( 'zrp_audio_verb', self::VERB ) : self::VERB;
		return preg_match( '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $v ) ? $v : self::VERB;
	}

	/** Is a transcription service registered? */
	public static function reader_ready() {
		return class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'get' ) && (bool) ZDZ_Capabilities::get( self::reader_verb() );
	}

	private static function reader( array $args ) {
		if ( ! self::reader_ready() ) {
			return array( 'success' => false, 'error' => 'no_reader', 'message' => __( 'Reading recordings needs a transcription service connected to the platform.', 'zorderz' ) );
		}
		return ZDZ_Capabilities::invoke( self::reader_verb(), $args );
	}

	/** Errors that will not get better by asking again. */
	private static function hard( $code ) {
		return in_array( (string) $code, array( 'bad_url', 'too_big', 'job_not_found', 'bad_request' ), true );
	}

	/**
	 * One step forward for one recording, as its owner. Returns the row as it now stands. One runner at
	 * a time -- the cron and the owner's page could both step it -- by claiming the row (lock_until) before anything is
	 * asked of the reader; the claim is let go (and updated_at moved, so the queue turns) whatever happens.
	 */
	public static function advance( array $a ) {
		global $wpdb;
		$uid = (int) $a['user_id'];
		if ( get_current_user_id() !== $uid ) {
			return $a; // the registry names who asks: only ever as the owner (cron switches to them first)
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$got = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET lock_until = %s WHERE id = %d AND (lock_until IS NULL OR lock_until < %s)', gmdate( 'Y-m-d H:i:s', time() + 600 ), (int) $a['id'], $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t() ($wpdb->prefix and a fixed name); values are placeholders
		if ( 1 !== (int) $got ) {
			return self::get( (int) $a['id'] ) ?: $a; // someone else is stepping it
		}
		try {
			$row = self::get( (int) $a['id'] );
			for ( $i = 0; $row && $i < 4; $i++ ) {
				$before = $row['status'] . '|' . $row['read_job'];
				$row    = self::step( $row );
				if ( ! $row || $row['status'] . '|' . $row['read_job'] === $before || in_array( $row['status'], array( 'done', 'failed', 'ready' ), true ) ) {
					break; // carry on while each step moved it (the reader's answer is often "still working")
				}
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET lock_until = NULL, updated_at = %s WHERE id = %d', gmdate( 'Y-m-d H:i:s' ), (int) $a['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t() ($wpdb->prefix and a fixed name); values are placeholders
		}
		return self::get( (int) $a['id'] ) ?: $a;
	}

	private static function step( array $a ) {
		$uid = (int) $a['user_id'];
		if ( ! zrp_user_can_use( $uid ) ) {
			self::set( (int) $a['id'], array( 'status' => 'failed', 'error' => 'The Route Planner is no longer on this account.' ) );
			return self::get( (int) $a['id'] );
		}
		$id = (int) $a['id'];
		if ( 'ready' === $a['status'] ) {
			$token = bin2hex( random_bytes( 32 ) );
			// 'submitted' BEFORE the reader is asked: its worker may come for the file before this call returns
			self::set( $id, array( 'status' => 'submitted', 'job' => '', 'token_hash' => hash( 'sha256', $token ), 'token_exp' => gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_S ) ) );
			$url = add_query_arg( array( 'k' => $token ), rest_url( ZRP_REST::ns() . ZRP_REST::BASE . '/audio-fetch/' . $id ) );
			$r = self::reader( array( 'action' => 'submit', 'kind' => 'transcribe', 'audio' => array( 'url' => $url, 'bytes' => (int) $a['bytes'], 'mime' => (string) $a['mime'] ), 'vocab' => self::vocab_words() ) );
			if ( ! empty( $r['success'] ) && empty( $r['denied'] ) && ! empty( $r['job'] ) ) {
				self::set( $id, array( 'status' => 'submitted', 'job' => (string) $r['job'], 'error' => '' ) );
			} else {
				self::set( $id, array( 'status' => 'ready', 'token_hash' => '' ) );
				self::fail_or_wait( $a, $r );
			}
		} elseif ( 'submitted' === $a['status'] && '' === (string) $a['job'] && strtotime( (string) ( $a['updated_at'] ?: $a['created_at'] ) . ' UTC' ) < time() - 120 ) {
			self::set( $id, array( 'status' => 'ready', 'token_hash' => '' ) ); // a request that died half-way: ask again
		} elseif ( 'submitted' === $a['status'] && '' !== (string) $a['job'] ) {
			$r = self::reader( array( 'action' => 'result', 'job' => (string) $a['job'] ) );
			if ( empty( $r['success'] ) || ! empty( $r['denied'] ) ) {
				self::fail_or_wait( $a, $r );
			} elseif ( 'failed' === ( $r['status'] ?? '' ) ) {
				if ( 'download' === ( $r['error'] ?? '' ) && (int) $a['tries'] < 3 && file_exists( self::path( $a ) ) ) {
					self::set( $id, array( 'status' => 'ready', 'job' => '', 'token_hash' => '', 'tries' => (int) $a['tries'] + 1, 'error' => self::why_failed( 'download' ) . ' Trying again.' ) );
				} else {
					self::set( $id, array( 'status' => 'failed', 'error' => self::why_failed( (string) ( $r['error'] ?? '' ) ) ) );
				}
			} elseif ( 'done' === ( $r['status'] ?? '' ) ) {
				$sents = (array) ( $r['sentences'] ?? array() );
				$next  = $r['next'] ?? null;
				for ( $guard = 0; null !== $next && $guard < 40; $guard++ ) {
					$p = self::reader( array( 'action' => 'result', 'job' => (string) $a['job'], 'offset' => (int) $next ) );
					if ( empty( $p['success'] ) ) {
						return self::get( $id ); // try the rest next time
					}
					$sents = array_merge( $sents, (array) ( $p['sentences'] ?? array() ) );
					$next  = $p['next'] ?? null;
				}
				if ( null !== $next ) {
					self::set( $id, array( 'status' => 'failed', 'error' => 'The recording is too long to read in one go (over 6,000 sentences).' ) );
					return self::get( $id );
				}
				self::drop_file( $a ); // the reader has it: the recording is not kept here any longer
				$work = self::place( $a, $sents, (string) ( $r['creation_time'] ?? '' ) );
				if ( $sents && ! $work['sentences'] ) {
					// words came back, none in a shape this site reads: a failure, never "done, no notes"
					self::set( $id, array( 'status' => 'failed', 'error' => 'The transcription service answered in a form this site does not read.', 'token_hash' => '' ) );
					return self::get( $id );
				}
				$fields = array( 'work' => self::seal_json( $work ), 'token_hash' => '', 'duration_s' => isset( $r['duration'] ) ? (int) round( (float) $r['duration'] ) : null,
					'creation_time' => substr( (string) ( $r['creation_time'] ?? '' ), 0, 40 ) );
				if ( ! $work['sentences'] ) {
					self::set( $id, array_merge( $fields, array( 'status' => 'done', 'work' => null, 'notes_made' => 0, 'how' => 'none' ) ) );
					return self::get( $id );
				}
				self::set( $id, array_merge( $fields, array( 'status' => 'reading', 'read_job' => '', 'tries' => 0 ) ) );
				return self::get( $id ); // advance() asks for the read at once
			}
		} elseif ( 'reading' === $a['status'] ) {
			$work = self::open_json( (string) $a['work'] );
			if ( ! $work ) {
				self::set( $id, array( 'status' => 'failed', 'error' => 'The words could not be opened (were the site\'s salts changed?). Upload the recording again.', 'work' => null ) );
				return self::get( $id );
			}
			if ( '' === (string) $a['read_job'] ) {
				// a row only where the street changes (the reader carries it on); "" where it is not known (a long
				// drive's full list would not fit a 64 KB request)
				$streets = array();
				$was     = null;
				foreach ( $work['sentences'] as $s ) {
					$now = (string) $s['street'] . '|' . (string) $s['zip'];
					if ( $now !== $was ) {
						$streets[] = array( (int) $s['i'], (string) $s['street'], (string) $s['zip'] );
						$was = $now;
					}
				}
				$rd = self::reader( array( 'action' => 'submit', 'kind' => 'read', 'of' => (string) $a['job'], 'streets' => array_slice( $streets, 0, 4000 ), 'vocab' => self::vocab_words() ) );
				if ( ! empty( $rd['success'] ) && empty( $rd['denied'] ) && ! empty( $rd['job'] ) ) {
					self::set( $id, array( 'read_job' => (string) $rd['job'], 'error' => '' ) );
				} elseif ( self::hard( (string) ( $rd['error'] ?? '' ) ) || ! empty( $rd['denied'] ) || (int) $a['tries'] >= 12 ) {
					// the reader cannot read them (its copy of the words is gone, or it is away for an hour): the site's own
					// rules, so the drive is not lost
					self::apply( $a, self::rules( $work['sentences'] ), 'rules' );
				} else {
					self::set( $id, array( 'tries' => (int) $a['tries'] + 1, 'error' => mb_substr( (string) ( $rd['message'] ?? '' ), 0, 160 ) ) );
				}
				return self::get( $id );
			}
			$r = self::reader( array( 'action' => 'result', 'job' => (string) $a['read_job'] ) );
			if ( empty( $r['success'] ) || ! empty( $r['denied'] ) || 'failed' === ( $r['status'] ?? '' ) ) {
				$code = (string) ( $r['error'] ?? '' );
				if ( self::hard( $code ) || 'failed' === ( $r['status'] ?? '' ) || (int) $a['tries'] >= 12 ) {
					self::apply( $a, self::rules( $work['sentences'] ), 'rules' );
				} else {
					self::set( $id, array( 'tries' => (int) $a['tries'] + 1, 'error' => (string) ( $r['message'] ?? '' ) ) );
				}
			} elseif ( 'done' === ( $r['status'] ?? '' ) ) {
				$res = array( 'notes' => (array) ( $r['notes'] ?? array() ), 'removed' => (array) ( $r['removed'] ?? array() ) );
				$next = $r['next'] ?? null;
				for ( $guard = 0; null !== $next && $guard < 40; $guard++ ) {
					$p = self::reader( array( 'action' => 'result', 'job' => (string) $a['read_job'], 'offset' => (int) $next, 'forget' => true ) );
					if ( empty( $p['success'] ) ) {
						return self::get( $id );
					}
					$res['notes']   = array_merge( $res['notes'], (array) ( $p['notes'] ?? array() ) );
					$res['removed'] = array_merge( $res['removed'], (array) ( $p['removed'] ?? array() ) );
					$next = $p['next'] ?? null;
				}
				if ( null !== $next ) {
					self::apply( $a, self::rules( $work['sentences'] ), 'rules' ); // the read did not all come: the rules, whole
					return self::get( $id );
				}
				if ( null === ( $r['next'] ?? null ) ) {
					self::reader( array( 'action' => 'result', 'job' => (string) $a['read_job'], 'forget' => true ) ); // cleared on the reader
				}
				self::apply( $a, $res, in_array( $r['how'] ?? '', array( 'model', 'rules', 'mixed' ), true ) ? $r['how'] : 'model' );
			}
		}
		return self::get( $id );
	}

	private static function why_failed( $code ) {
		$w = array( 'download' => 'The transcription service could not download it.', 'no_ffmpeg' => 'The transcription service is missing its audio converter.',
			'no_whisper' => 'The transcription service is missing its speech model.',
			'no_whisper_model' => 'No speech model is chosen for transcription yet: an administrator sets one on the transcription service.',
			'convert' => 'The recording could not be opened (an unusual format?).',
			'too_big' => 'The recording is too large.', 'empty' => 'The recording was empty.', 'gone' => 'The transcription service lost the words; try again.' );
		return $w[ $code ] ?? 'The transcription service could not read it (' . preg_replace( '/[^a-z_]/', '', $code ) . ').';
	}

	/** Not this time: wait and try again (the reader asleep or out of date), or fail for good. */
	private static function fail_or_wait( array $a, array $r ) {
		$code = (string) ( $r['error'] ?? ( $r['reason'] ?? '' ) );
		$tries = (int) $a['tries'] + 1;
		if ( ! empty( $r['denied'] ) || self::hard( $code ) || $tries > 600 ) { // 600 x 5 min: about two days of the reader being away
			self::set( (int) $a['id'], array( 'status' => 'failed', 'error' => mb_substr( (string) ( $r['message'] ?? $code ), 0, 160 ), 'tries' => $tries, 'token_hash' => '' ) );
		} else {
			self::set( (int) $a['id'], array( 'tries' => $tries, 'error' => mb_substr( (string) ( $r['message'] ?? '' ), 0, 160 ) ) );
		}
	}

	/**
	 * The start of a part, in ms. A recording made in the app knows it; a Voice Memos file's own creation time (from the
	 * file, the phone reads it; the reader's own probe as a second opinion) is used when the phone could not read one.
	 */
	public static function start_ms( array $a, $creation_time = '' ) {
		$s = (int) $a['started_ms'];
		if ( 'memo' === $a['kind'] && $s <= 0 && '' !== $creation_time && false !== ( $t = strtotime( $creation_time ) ) ) {
			$s = $t * 1000;
		}
		return $s + (int) $a['offset_ms'];
	}

	/** Where the car was at $ms on the drive's track ([lat, lng, unix s] points): {lat, lng, heading, speed} or null. */
	public static function position( array $track, $ms ) {
		$t = $ms / 1000.0;
		$n = count( $track );
		if ( ! $n ) {
			return null;
		}
		if ( $t <= $track[0][2] ) {
			return ( $track[0][2] - $t <= self::FIX_GAP_S ) ? array( 'lat' => (float) $track[0][0], 'lng' => (float) $track[0][1], 'heading' => $n > 1 ? ZRP_Snap::bearing( $track[0], $track[1] ) : null, 'speed' => null ) : null;
		}
		if ( $t >= $track[ $n - 1 ][2] ) {
			$l = $track[ $n - 1 ];
			return ( $t - $l[2] <= self::FIX_GAP_S ) ? array( 'lat' => (float) $l[0], 'lng' => (float) $l[1], 'heading' => $n > 1 ? ZRP_Snap::bearing( $track[ $n - 2 ], $l ) : null, 'speed' => null ) : null;
		}
		$lo = 0; $hi = $n - 1;
		while ( $hi - $lo > 1 ) {
			$mid = intdiv( $lo + $hi, 2 );
			if ( $track[ $mid ][2] <= $t ) { $lo = $mid; } else { $hi = $mid; }
		}
		$a = $track[ $lo ]; $b = $track[ $hi ];
		$span = max( 1, $b[2] - $a[2] );
		if ( min( $t - $a[2], $b[2] - $t ) > self::FIX_GAP_S ) {
			return null; // a long gap in the track (GPS lost): not a guess
		}
		$f = ( $t - $a[2] ) / $span;
		return array( 'lat' => $a[0] + ( $b[0] - $a[0] ) * $f, 'lng' => $a[1] + ( $b[1] - $a[1] ) * $f, 'heading' => ZRP_Snap::bearing( $a, $b ),
			'speed' => ZRP_DB::dist_m( $a[0], $a[1], $b[0], $b[1] ) / $span );
	}

	/** Each sentence's moment, position and street: {sentences: [{i, t, e, text, ms, lat, lng, heading, speed, street, zip}]}. */
	public static function place( array $a, array $sents, $creation_time = '' ) {
		$d     = ZRP_DB::get_drive( (int) $a['drive_id'] );
		$track = $d ? json_decode( (string) $d['track_json'], true ) : array();
		$track = is_array( $track ) ? array_values( array_filter( $track, function ( $p ) { return is_array( $p ) && count( $p ) >= 3 && $p[2] > 0; } ) ) : array();
		$start = self::start_ms( $a, $creation_time );
		$out   = array();
		$cache = array();
		foreach ( array_slice( $sents, 0, 4000 ) as $s ) {
			if ( ! is_array( $s ) || ! isset( $s['i'], $s['t'] ) ) {
				continue;
			}
			$ms  = $start + (int) round( (float) $s['t'] * 1000 );
			$pos = $start > 0 ? self::position( $track, $ms ) : null;
			$row = array( 'i' => (int) $s['i'], 't' => (float) $s['t'], 'e' => (float) ( $s['e'] ?? $s['t'] ), 'text' => mb_substr( (string) ( $s['text'] ?? '' ), 0, 400 ),
				'ms' => $ms, 'lat' => null, 'lng' => null, 'heading' => null, 'speed' => null, 'street' => '', 'zip' => '' );
			if ( $pos ) {
				$row = array_merge( $row, $pos );
				$k = round( $pos['lat'], 4 ) . ',' . round( $pos['lng'], 4 );
				if ( ! isset( $cache[ $k ] ) ) {
					$sn = ZRP_Snap::snap( array( 'lat' => $pos['lat'], 'lng' => $pos['lng'], 'heading' => $pos['heading'], 'scope' => 'here', 'side' => '' ) );
					$cache[ $k ] = array( (string) $sn['street'], (string) $sn['zip'] );
				}
				list( $row['street'], $row['zip'] ) = $cache[ $k ];
			}
			$out[] = $row;
		}
		return array( 'sentences' => $out );
	}

	/** The site's own reading when the reader's is not to be had: the same rules as live dictation. */
	public static function rules( array $sents ) {
		$notes = array(); $removed = array();
		foreach ( $sents as $s ) {
			$t = trim( preg_replace( '/\s+/', ' ', preg_replace( "/[^a-z' ]+/", ' ', strtolower( (string) $s['text'] ) ) ) );
			if ( preg_match( '/^(?:(?:stop|end|finish) (?:the )?(?:survey|drive|driving|recording)(?: survey)?|pause(?: notes| listening| recording)?|stop listening|mute|resume(?: notes| listening| recording)?|start listening|unmute|listen again|keep (?:that|it)|confirm(?: that| it)?|save that)$/', $t ) ) {
				continue;
			}
			if ( preg_match( '/^(?:no )?(?:scratch that|delete that|undo(?: that)?|cancel that|never ?mind|forget that|strike that|ignore that|erase that|disregard that)$/', $t ) ) {
				if ( $notes && (float) $s['t'] - (float) end( $notes )['e'] <= 45 ) {
					$n = array_pop( $notes );
					$removed[] = array( 'ids' => $n['ids'], 't' => $n['t'], 'text' => $n['text'], 'why' => '"' . trim( (string) $s['text'], ' .' ) . '" right after it' );
				}
				continue;
			}
			if ( preg_match( '/^\W*(?:scratch that|delete that|cancel that|strike that|never ?mind|no wait|wait no|ignore that|erase that|disregard that)\b/i', (string) $s['text'] ) ) {
				// "Scratch that, three pools ...": takes back the note before it, keeps what follows
				if ( $notes && (float) $s['t'] - (float) end( $notes )['e'] <= 45 ) {
					$n = array_pop( $notes );
					$removed[] = array( 'ids' => $n['ids'], 't' => $n['t'], 'text' => $n['text'], 'why' => 'taken back by the next words' );
				}
			}
			list( $kept, $gone ) = ZRP_Vocab::correct( (string) $s['text'] );
			if ( '' !== $gone ) {
				$removed[] = array( 'ids' => array( (int) $s['i'] ), 't' => (float) $s['t'], 'text' => $gone, 'why' => 'corrected in the same sentence' );
			}
			if ( '' !== $kept ) {
				$notes[] = array( 'ids' => array( (int) $s['i'] ), 't' => (float) $s['t'], 'e' => (float) $s['e'], 'text' => $kept );
			}
		}
		return array( 'notes' => $notes, 'removed' => $removed );
	}

	/** Two notes that say the same thing within 10 s of each other (a Voice Memos file over a drive dictated live). */
	private static function same_as_live( $drive_id, $uid, $ms, $text ) {
		global $wpdb;
		$rows = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT said FROM ' . ZRP_DB::t_notes() . " WHERE drive_id = %d AND user_id = %d AND source IN ('live','typed') AND said_at BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from ZRP_DB::t_notes() ($wpdb->prefix and a fixed name); values are placeholders
			(int) $drive_id, (int) $uid, gmdate( 'Y-m-d H:i:s', (int) floor( $ms / 1000 ) - 10 ), gmdate( 'Y-m-d H:i:s', (int) floor( $ms / 1000 ) + 10 ) ) );
		$mine = array_unique( preg_split( '/\W+/', strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY ) );
		foreach ( $rows as $said ) {
			$theirs = array_unique( preg_split( '/\W+/', strtolower( (string) $said ), -1, PREG_SPLIT_NO_EMPTY ) );
			if ( $mine && count( array_intersect( $mine, $theirs ) ) >= 0.5 * count( $mine ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Notes that lead into a code ("... the gate code is.") take the next sentence with them when it starts with the
	 * digits.
	 */
	public static function join_codes( array $notes, array $by ) {
		usort( $notes, function ( $x, $y ) { return ( $x['t'] ?? 0 ) <=> ( $y['t'] ?? 0 ); } );
		$out = array();
		for ( $i = 0; $i < count( $notes ); $i++ ) {
			$n = $notes[ $i ];
			$nx = $notes[ $i + 1 ] ?? null;
			if ( $nx && ZRP_Codes::leads_in( (string) $n['text'] ) && (float) ( $nx['t'] ?? 0 ) - (float) ( $n['e'] ?? $n['t'] ?? 0 ) <= 10
				&& null !== ZRP_Codes::normalize_code( (string) $nx['text'] ) ) {
				$n['text'] = rtrim( (string) $n['text'], " .,;:" ) . ' ' . $nx['text'];
				$n['ids']  = array_merge( (array) $n['ids'], (array) $nx['ids'] );
				$n['e']    = $nx['e'] ?? $n['e'] ?? null;
				$i++;
			}
			$out[] = $n;
		}
		return $out;
	}

	/** The read, kept as notes on the drive. */
	public static function apply( array $a, array $res, $how ) {
		$work = self::open_json( (string) $a['work'] );
		$by   = array();
		foreach ( (array) ( $work['sentences'] ?? array() ) as $s ) {
			$by[ (int) $s['i'] ] = $s;
		}
		$res['notes'] = self::join_codes( (array) ( $res['notes'] ?? array() ), $by );
		$made = 0; $tried = 0; $kept = 0;
		foreach ( array( 'notes' => 'pending', 'removed' => 'retracted' ) as $k => $status ) {
			foreach ( array_values( (array) ( $res[ $k ] ?? array() ) ) as $idx => $n ) {
				$ids = array_values( array_filter( array_map( 'intval', (array) ( $n['ids'] ?? array() ) ), function ( $i ) use ( $by ) { return isset( $by[ $i ] ); } ) );
				$txt = trim( (string) ( $n['text'] ?? '' ) );
				if ( '' === $txt ) {
					continue;
				}
				$tried++;
				if ( ! $ids ) {
					continue;
				}
				$s = $by[ $ids[0] ];
				if ( 'pending' === $status && self::same_as_live( (int) $a['drive_id'], (int) $a['user_id'], (int) $s['ms'], $txt ) ) {
					$kept++;
					continue;
				}
				$r = ZRP_REST::make_note( array(
					// one id per item of the read
					'uid' => (int) $a['user_id'], 'drive_id' => (int) $a['drive_id'], 'client_id' => 'a' . (int) $a['id'] . '-' . $k[0] . $idx . '-' . $ids[0],
					'said' => $txt, 'lat' => $s['lat'], 'lng' => $s['lng'], 'acc' => null, 'speed' => $s['speed'], 'heading' => $s['heading'],
					'said_at_ms' => (int) $s['ms'], 'source' => 'audio', 'audio_id' => (int) $a['id'], 'status' => $status,
					'why' => 'retracted' === $status ? mb_substr( (string) ( $n['why'] ?? 'a correction' ), 0, 120 ) : '', 'correct' => false,
				) );
				if ( ! empty( $r['id'] ) ) {
					$kept++;
					if ( 'pending' === $status ) {
						$made++;
					}
				}
			}
		}
		if ( $tried > 0 && 0 === $kept ) {
			// words came back but nothing could be kept: a failure, never "done, no notes"
			self::set( (int) $a['id'], array( 'status' => 'failed', 'error' => 'The notes could not be saved or placed on the drive.', 'how' => substr( (string) $how, 0, 8 ) ) );
			return;
		}
		self::set( (int) $a['id'], array( 'status' => 'done', 'work' => null, 'notes_made' => $made, 'how' => substr( (string) $how, 0, 8 ), 'error' => '' ) );
		ZRP_DB::end_drive_count( (int) $a['drive_id'] );
	}

	/* ------------------------------------------------------------------ cron */

	public static function schedules( $s ) {
		$s = is_array( $s ) ? $s : array();
		$s['zrp_5min'] = array( 'interval' => 300, 'display' => 'Every 5 minutes (Route Planner recordings)' );
		return $s;
	}

	public static function ensure_cron() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 120, 'zrp_5min', self::HOOK );
		}
	}

	/** Every 5 minutes: the recordings waiting on the reader, each as its owner; then the tidy-up. */
	public static function tick() {
		global $wpdb;
		$was  = get_current_user_id();
		$rows = (array) $wpdb->get_results( "SELECT * FROM " . self::t() . " WHERE status IN ('ready','submitted','reading') ORDER BY updated_at ASC LIMIT 8", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t() ($wpdb->prefix and a fixed name); the query takes no values
		foreach ( $rows as $a ) {
			if ( ! get_userdata( (int) $a['user_id'] ) ) {
				// an owner who no longer exists would hold the head of the queue for ever
				self::drop_file( $a );
				self::set( (int) $a['id'], array( 'status' => 'failed', 'error' => 'The person who recorded it is no longer on the site.', 'work' => null, 'token_hash' => '' ) );
				continue;
			}
			wp_set_current_user( (int) $a['user_id'] );
			try {
				self::advance( $a );
			} catch ( \Throwable $e ) {
				error_log( 'ZRP_Audio: recording ' . (int) $a['id'] . ' - ' . $e->getMessage() );
			}
		}
		wp_set_current_user( $was );
		self::tidy();
	}

	/** Words taken back are kept 30 days for an Undo; an upload left half-way, 3 days; a failed recording's file, 7. */
	public static function tidy() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . ZRP_DB::t_notes() . " WHERE status = 'retracted' AND COALESCE(confirmed_at, created_at) < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from ZRP_DB::t_notes() ($wpdb->prefix and a fixed name); values are placeholders
		$old = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " WHERE (status = 'uploading' AND created_at < %s) OR (status = 'failed' AND updated_at < %s)", gmdate( 'Y-m-d H:i:s', time() - 3 * 86400 ), gmdate( 'Y-m-d H:i:s', time() - 7 * 86400 ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t() ($wpdb->prefix and a fixed name); values are placeholders
		foreach ( $old as $a ) {
			self::drop_file( $a );
			if ( 'uploading' === $a['status'] ) {
				$wpdb->delete( self::t(), array( 'id' => (int) $a['id'] ) );
			} else {
				self::set( (int) $a['id'], array( 'work' => null ) );
			}
		}
	}
}
