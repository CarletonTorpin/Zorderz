<?php
/**
 * ZRP_REST -- the Route Planner page's own routes, <platform namespace>/route-planner/.
 *
 * Every route: signed in, holds the Route Planner (zrp_user_can_use: never the shared kiosk), the person is the session
 *. A note is parsed, scrubbed and snapped HERE, from the words and the position the phone sent: the phone's own
 * suggestion is never trusted. Confirming is a person's press or "keep that"; a note is confirmed or discarded
 * by the person who said it, or by the admin tier.
 *
 *   GET  boot                 the vocabulary, this person's pending notes and recent drives, the settings the page needs
 *   POST drives               start a survey drive (ends this person's earlier running one)
 *   POST drives/<id>          {track: [[lat, lng, unixS], ...], end: bool}: add points, or end it
 *   GET  drives/<id>          one drive with its thinned track (the person's own; the admin tier: anyone's)
 *   POST notes                {drive_id, client_id, lat, lng, acc, speed, heading, said, said_at_ms, typed}
 *   POST notes/<id>           {op: confirm | discard | update, tags: [slug], scope, side}
 *   GET  notes                ?status=pending|confirmed|retracted&mine=1&box=xmin,ymin,xmax,ymax&drive=<id>
 *   
 *   POST notes/<id>           also {op: retract} ("scratch that": kept for an Undo, never counted) and
 *                             {op: restore} (the Undo: back to pending, its tags suggested again)
 *   POST audio, audio/<id>/chunk, audio/<id>/done, GET audio, POST audio/<id>, GET audio-fetch/<id>  (ZRP_Audio)
 *   GET  codes                the gated communities' codes on file, never a code (ZRP_Codes::out)
 *   POST codes                {kind, label, code, note_id | lat+lng}: seal a typed code
 *   POST codes/<id>/reveal    the code, to the admin tier or the walker assigned a route there today; logged
 *   POST codes/<id>/delete    the admin tier, or the person who added it
 *   
 *   POST plan, plan/area, plan/send, plan/create; GET plan/sends   planned routes (ZRP_Plan)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRP_REST {

	const BASE = '/route-planner';   // every route sits under the platform's namespace: <ZDZ_REST_NS>/route-planner/...

	/** The platform's REST namespace. */
	public static function ns() {
		return defined( 'ZDZ_REST_NS' ) ? ZDZ_REST_NS : 'zorderz/v1';
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function may() {
		return is_user_logged_in() && zrp_user_can_use( get_current_user_id() );
	}

	public static function routes() {
		$may = array( __CLASS__, 'may' );
		register_rest_route( self::ns(), self::BASE . '/boot', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'boot' ), 'permission_callback' => $may ) );
		register_rest_route( self::ns(), self::BASE . '/drives', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'start' ), 'permission_callback' => $may ) );
		register_rest_route( self::ns(), self::BASE . '/drives/(?P<id>\d+)', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'drive_update' ), 'permission_callback' => $may ),
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'drive_get' ), 'permission_callback' => $may ),
		) );
		register_rest_route( self::ns(), self::BASE . '/notes', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'note_add' ), 'permission_callback' => $may ),
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'notes_list' ), 'permission_callback' => $may ),
		) );
		register_rest_route( self::ns(), self::BASE . '/notes/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'note_update' ), 'permission_callback' => $may ) );
		// recordings (ZRP_Audio)
		register_rest_route( self::ns(), self::BASE . '/audio', array(
			array( 'methods' => 'POST', 'callback' => array( 'ZRP_Audio', 'rest_create' ), 'permission_callback' => $may ),
			array( 'methods' => 'GET', 'callback' => array( 'ZRP_Audio', 'rest_list' ), 'permission_callback' => $may ),
		) );
		register_rest_route( self::ns(), self::BASE . '/audio/(?P<id>\d+)/chunk', array( 'methods' => 'POST', 'callback' => array( 'ZRP_Audio', 'rest_chunk' ), 'permission_callback' => $may ) );
		register_rest_route( self::ns(), self::BASE . '/audio/(?P<id>\d+)/done', array( 'methods' => 'POST', 'callback' => array( 'ZRP_Audio', 'rest_done' ), 'permission_callback' => $may ) );
		register_rest_route( self::ns(), self::BASE . '/audio/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( 'ZRP_Audio', 'rest_act' ), 'permission_callback' => $may ) );
		// the transcription service has no WordPress session: the one-part token in ?k= is the key (ZRP_Audio::rest_fetch)
		register_rest_route( self::ns(), self::BASE . '/audio-fetch/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( 'ZRP_Audio', 'rest_fetch' ), 'permission_callback' => '__return_true' ) ); // sentinel:allow transcription service fetch; single-use hashed token checked in ZRP_Audio::rest_fetch
		// sealed gate codes (ZRP_Codes)
		register_rest_route( self::ns(), self::BASE . '/codes', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'codes_list' ), 'permission_callback' => $may ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'code_add' ), 'permission_callback' => $may ),
		) );
		register_rest_route( self::ns(), self::BASE . '/codes/(?P<id>\d+)/reveal', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'code_reveal' ), 'permission_callback' => $may ) );
		register_rest_route( self::ns(), self::BASE . '/codes/(?P<id>\d+)/delete', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'code_delete' ), 'permission_callback' => $may ) );
		// planned routes (ZRP_Plan)
		ZRP_Plan::routes( $may );
	}

	private static function err( $code, $msg, $status = 400 ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	/** The vocabulary as the page shows it: slug, label, product label. */
	public static function vocab_out() {
		$out = array();
		foreach ( ZRP_Vocab::vocabulary() as $slug => $v ) {
			$out[] = array( 'slug' => $slug, 'label' => $v['label'], 'product' => $v['product'], 'product_label' => $v['product_label'], 'flags' => $v['flags'] ?? array(), 'choices' => $v['choices'] ?? array() );
		}
		return $out;
	}

	/**
	 * A note as the page gets it. The person's own (and the admin tier's view): everything. Someone else's: no drive,
	 * no client id, the day not the minute, and no house number -- in the field or in the words -- unless Doorhangers
	 * lets the viewer see house numbers.
	 */
	public static function note_out( array $n, $vocab = null ) {
		$vocab = $vocab ?: ZRP_Vocab::vocabulary();
		$sug   = array_values( array_filter( explode( ',', (string) $n['suggested'] ), function ( $t ) use ( $vocab ) { return isset( $vocab[ $t ] ); } ) );
		$me    = get_current_user_id();
		$own   = (int) $n['user_id'] === $me || zrp_user_is_admin( $me );
		$nums  = $own || zrp_may_see_numbers( $me );
		return array(
			'id'        => (int) $n['id'],
			'drive_id'  => $own ? (int) $n['drive_id'] : 0,
			'mine'      => (int) $n['user_id'] === $me,
			'client_id' => $own ? (string) $n['client_id'] : '',
			'at'        => $own ? (string) $n['created_at'] : substr( (string) $n['created_at'], 0, 10 ),
			'lat'       => null === $n['lat'] ? null : (float) $n['lat'],
			'lng'       => null === $n['lng'] ? null : (float) $n['lng'],
			'said'      => $nums ? (string) $n['said'] : preg_replace( '/\b\d+\b/', '[number]', (string) $n['said'] ),
			'scope'     => (string) $n['scope'],
			'side'      => (string) $n['side'],
			'street'    => (string) $n['street'],
			'zip'       => (string) $n['zip'],
			'city'      => (string) $n['city'],
			'seg_id'    => null === $n['seg_id'] ? null : (int) $n['seg_id'],
			'house'     => $nums ? (string) $n['house_number'] : '',
			'snapped'   => (int) $n['snapped'],
			'status'    => (string) $n['status'],
			'suggested' => $sug,
			'tags'      => isset( $n['tags'] ) ? array_values( (array) $n['tags'] ) : ZRP_DB::tags_of( (int) $n['id'] ),
			'source'    => (string) ( $n['source'] ?? 'live' ),
			'why'       => $own ? (string) ( $n['why'] ?? '' ) : '',
			'retract_of'=> $own && ! empty( $n['retract_of'] ) ? (int) $n['retract_of'] : null,
		);
	}

	public static function boot( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		$s   = zrp_get_settings();
		$v   = ZRP_Vocab::vocabulary();
		return rest_ensure_response( array(
			'vocab'   => self::vocab_out(),
			'pending' => array_map( function ( $n ) use ( $v ) { return self::note_out( $n, $v ); }, ZRP_DB::notes( array( 'user' => $uid, 'status' => array( 'pending' ) ), 200 ) ),
			'drives'  => ZRP_DB::drives( $uid, false, 10 ),
			'readback'=> ! empty( $s['readback'] ),
			'snap'    => class_exists( 'ZDZ_Capabilities' ) && ZDZ_Capabilities::get( ZRP_Snap::VERB ) ? 1 : 0,

			'retracted' => array_map( function ( $n ) use ( $v ) { return self::note_out( $n, $v ); }, ZRP_DB::notes( array( 'user' => $uid, 'status' => array( 'retracted' ), 'since' => gmdate( 'Y-m-d H:i:s', time() - 7 * 86400 ) ), 100 ) ),
			'audio'     => array_map( array( 'ZRP_Audio', 'out' ), ZRP_Audio::mine( $uid, 20 ) ),
			'record'    => ! empty( $s['record'] ) && ZRP_Audio::ready(),
			'reads'     => ZRP_Audio::reader_ready() ? 1 : 0,
			'codes'     => ZRP_Codes::ready() ? 1 : 0,
			// planning routes
			'plan'      => self::plan_boot( $uid, $s ),
		) );
	}

	/**
	 * What the Plan tab needs to know before it asks anything: whether a ranker can rank (its verb is there),
	 * whether this person can send to Doorhangers, the weather limits, and -- for the admin tier, who may assign a route
	 * to someone else (Doorhangers' rule) -- the people who hold Doorhangers. Names only; never a contact.
	 */
	public static function plan_boot( $uid, array $s ) {
		$caps   = class_exists( 'ZDZ_Capabilities' );
		$sender = function_exists( 'zdh_user_can_use' ) && zdh_user_can_use( (int) $uid ) && has_filter( 'zdh_plan_preview' );
		$people = array();
		if ( $sender && zrp_user_is_admin( (int) $uid ) && function_exists( 'zdh_people' ) ) {
			foreach ( (array) zdh_people() as $p ) {
				$people[] = array( 'id' => (int) $p['id'], 'name' => (string) $p['name'] );
			}
		}
		return array(
			'rank'     => ZRP_Plan::ranker_ready() ? 1 : 0,
			'houses'   => $caps && ZDZ_Capabilities::get( ZRP_Plan::VERB_AREA ) ? 1 : 0,
			'send'     => $sender ? 1 : 0,
			'rehang'   => (int) $s['rehang_days'],
			'limits'   => ZRP_Weather::limits(),
			'per_hour' => (int) round( ZRP_Plan::per_hour() ),
			'today'    => ZRP_Plan::today(),
			'people'   => $people,
			'negative' => function_exists( 'zrp_plan_negative_tags' ) ? zrp_plan_negative_tags() : array(),
			'max'      => array( 'streets' => ZRP_Plan::MAX_STREETS, 'houses' => ZRP_Plan::MAX_HOUSES, 'count' => ZRP_Plan::MAX_COUNT, 'hours' => ZRP_Plan::MAX_HOURS ),
		);
	}

	public static function start( WP_REST_Request $req ) {
		global $wpdb;
		$uid = get_current_user_id();
		// one survey at a time per person: an earlier one left running (a phone that died) is ended
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . ZRP_DB::t_drives() . " WHERE user_id = %d AND status = 'running'", $uid ) ) as $old ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from ZRP_DB::t_drives() ($wpdb->prefix and a fixed name); values are placeholders
			ZRP_DB::end_drive( (int) $old );
		}
		$id = ZRP_DB::start_drive( $uid );
		if ( ! $id ) {
			return self::err( 'db', 'The survey could not be started.', 500 );
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	private static function own_drive( $id ) {
		$d = ZRP_DB::get_drive( (int) $id );
		if ( ! $d ) {
			return self::err( 'not_found', 'That survey drive was not found.', 404 );
		}
		$me = get_current_user_id();
		if ( (int) $d['user_id'] !== $me && ! zrp_user_is_admin( $me ) ) {
			return self::err( 'forbidden', 'That is someone else\'s drive.', 403 );
		}
		return $d;
	}

	public static function drive_update( WP_REST_Request $req ) {
		$d = self::own_drive( $req['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		if ( (int) $d['user_id'] !== get_current_user_id() ) {
			return self::err( 'forbidden', 'Only the driver adds to a drive.', 403 );
		}
		$pts = array_slice( (array) $req->get_param( 'track' ), 0, 3000 );
		if ( $pts && 'running' === $d['status'] && ! ZRP_DB::add_track( (int) $d['id'], $pts ) ) {
			return self::err( 'db', 'The drive could not be saved.', 500 );
		}
		if ( $req->get_param( 'end' ) ) {
			ZRP_DB::end_drive( (int) $d['id'] );
		}
		$d = ZRP_DB::get_drive( (int) $d['id'] );
		return rest_ensure_response( array( 'id' => (int) $d['id'], 'status' => $d['status'], 'distance_m' => (int) $d['distance_m'], 'note_count' => (int) $d['note_count'] ) );
	}

	public static function drive_get( WP_REST_Request $req ) {
		$d = self::own_drive( $req['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$t = json_decode( (string) $d['track_json'], true );
		return rest_ensure_response( array( 'id' => (int) $d['id'], 'started_at' => $d['started_at'], 'ended_at' => $d['ended_at'], 'status' => $d['status'],
			'distance_m' => (int) $d['distance_m'], 'note_count' => (int) $d['note_count'], 'track' => is_array( $t ) ? $t : array() ) );
	}

	private static function num( $v, $lo, $hi ) {
		return ( null !== $v && '' !== $v && is_numeric( $v ) && (float) $v >= $lo && (float) $v <= $hi ) ? (float) $v : null;
	}

	public static function note_add( WP_REST_Request $req ) {
		$uid  = get_current_user_id();
		$said = trim( (string) $req->get_param( 'said' ) );
		if ( '' === $said ) {
			return self::err( 'empty', 'Nothing was said.' );
		}
		$lat = self::num( $req->get_param( 'lat' ), -90, 90 );
		$lng = self::num( $req->get_param( 'lng' ), -180, 180 );
		if ( null === $lat || null === $lng ) {
			return self::err( 'no_position', 'The note has no position. Wait for GPS and say it again.' );
		}
		$drive = (int) $req->get_param( 'drive_id' );
		if ( $drive ) {
			$d = ZRP_DB::get_drive( $drive );
			if ( ! $d || (int) $d['user_id'] !== $uid ) {
				$drive = 0; // never someone else's drive
			}
		}
		$r = self::make_note( array(
			'uid' => $uid, 'drive_id' => $drive, 'client_id' => (string) $req->get_param( 'client_id' ), 'said' => $said,
			'lat' => $lat, 'lng' => $lng, 'acc' => self::num( $req->get_param( 'acc' ), 0, 5000 ), 'speed' => self::num( $req->get_param( 'speed' ), 0, 90 ),
			'heading' => self::num( $req->get_param( 'heading' ), 0, 360 ), 'said_at_ms' => self::num( $req->get_param( 'said_at_ms' ), 1.0e12, 4.0e12 ),
			'source' => $req->get_param( 'typed' ) ? 'typed' : 'live',
			// "scratch that" said before the note left the phone: it still comes, as words taken back (an Undo)
			'status' => $req->get_param( 'retracted' ) ? 'retracted' : 'pending', 'why' => $req->get_param( 'retracted' ) ? '"scratch that" right after it' : '',
			'correct' => true,
		) );
		if ( ! $r['id'] ) {
			return self::err( 'db', 'The note could not be saved.', 500 );
		}
		$out = self::note_out( ZRP_DB::get_note( $r['id'] ) );
		$out['new']      = $r['new'];
		$out['negated']  = $r['negated'];
		$out['why_snap'] = $r['why_snap'];
		$out['removed']  = $r['removed'];
		$out['code']     = $r['code'] ? true : false;   // a code was heard and sealed; never the code
		return rest_ensure_response( $out );
	}

	/**
	 * one note kept, from live dictation, typing or a recording. The order matters for privacy: a gate code is
	 * cut out and sealed FIRST (ZRP_Codes::extract), then a correction inside the sentence is split off (live and typed
	 * only: a recording was read by the transcription service), then the words are parsed, snapped and scrubbed. The part a correction took
	 * back is kept as its own 'retracted' note beside it, for an Undo.
	 * $in: uid, drive_id, client_id, said, lat, lng, acc, speed, heading, said_at_ms, source, status, why, audio_id, correct.
	 * Returns {id, new, negated, why_snap, removed, code}.
	 */
	public static function make_note( array $in ) {
		$vocab = ZRP_Vocab::vocabulary();
		$said  = mb_substr( trim( (string) $in['said'] ), 0, 2000 );
		$lat   = isset( $in['lat'] ) && is_numeric( $in['lat'] ) ? (float) $in['lat'] : null;
		$lng   = isset( $in['lng'] ) && is_numeric( $in['lng'] ) ? (float) $in['lng'] : null;
		// the correction FIRST, so "gate code 1234, scratch that, 5678" seals 5678, never the number taken back
		$removed = '';
		if ( ! empty( $in['correct'] ) && 'retracted' !== ( $in['status'] ?? '' ) ) {
			list( $kept, $removed ) = ZRP_Vocab::correct( $said );
			if ( '' !== $removed ) {
				$said = '' !== $kept ? $kept : $said;
			}
			if ( '' === $kept && '' !== $removed ) {
				$in['status'] = 'retracted'; // "pools here, scratch that": all of it taken back
				$in['why']    = 'corrected in the same sentence';
				$removed      = '';
			}
		}
		// a code in words taken back is never sealed and never kept
		if ( '' !== $removed && ( $rc = ZRP_Codes::extract_all( $removed ) ) ) {
			$removed = str_replace( 'code sealed]', 'code taken back]', $rc[1] );
		}
		if ( '' !== $removed ) {   // what this app's reader misses ("lockbox 5521") is not kept either
			$removed = str_replace( 'code sealed]', 'code taken back]', (string) ZRP_Codes::cut( $removed ) );
		}
		$codes = array();
		if ( $hit = ZRP_Codes::extract_all( $said ) ) {
			$codes = 'retracted' === ( $in['status'] ?? '' ) ? array() : $hit[0];
			$said  = ZRP_Codes::ready() ? $hit[1] : str_replace( 'code sealed]', 'code not kept: this site cannot seal it]', $hit[1] );
			if ( 'retracted' === ( $in['status'] ?? '' ) ) {
				$said = str_replace( 'code sealed]', 'code taken back]', $hit[1] );
			}
		}
		// the shared vault's reader is wider than this app's ("lockbox 5521", "Gate 1234"); what it finds that
		// extract_all() left is cut here and sealed under the street once the note is placed (below)
		$said_pre = $said;
		if ( 'retracted' === ( $in['status'] ?? '' ) ) {
			$said = str_replace( 'code sealed]', 'code taken back]', (string) ZRP_Codes::cut( $said ) );   // taken back: never kept, never sealed
			$said_pre = $said;
		} elseif ( ZRP_Codes::spans( $said ) ) {
			$said = ZRP_Codes::cut( $said );
		}
		$parsed  = ZRP_Vocab::parse( $said, $vocab );
		$heading = isset( $in['heading'] ) && is_numeric( $in['heading'] ) ? (float) $in['heading'] : null;
		$snap    = ( null !== $lat && null !== $lng ) ? ZRP_Snap::snap( array( 'lat' => $lat, 'lng' => $lng, 'heading' => $heading, 'scope' => $parsed['scope'], 'side' => $parsed['side'] ) )
			: array( 'street' => '', 'street_key' => '', 'zip' => '', 'city' => '', 'seg_id' => null, 'house_number' => '', 'snapped' => 0, 'side' => '', 'why' => 'no position at that moment' );
		$house   = 'house' === $parsed['scope'] ? (string) $snap['house_number'] : '';
		$at_ms   = isset( $in['said_at_ms'] ) && is_numeric( $in['said_at_ms'] ) ? (float) $in['said_at_ms'] : null;
		$cid     = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $in['client_id'] ?? '' ) ), 0, 40 );
		$status  = in_array( $in['status'] ?? '', array( 'pending', 'retracted' ), true ) ? $in['status'] : 'pending';
		$base = array(
			'drive_id'     => (int) ( $in['drive_id'] ?? 0 ),
			'user_id'      => (int) $in['uid'],
			'said_at'      => null !== $at_ms ? gmdate( 'Y-m-d H:i:s', (int) floor( $at_ms / 1000 ) ) : null,
			'lat'          => null !== $lat ? round( $lat, 7 ) : null,
			'lng'          => null !== $lng ? round( $lng, 7 ) : null,
			'acc_m'        => isset( $in['acc'] ) && is_numeric( $in['acc'] ) ? (int) round( (float) $in['acc'] ) : null,
			'speed_mps'    => isset( $in['speed'] ) && is_numeric( $in['speed'] ) ? round( min( 90, max( 0, (float) $in['speed'] ) ), 2 ) : null,
			'heading'      => null !== $heading ? (int) round( $heading ) : null,
			'side'         => (string) $snap['side'],
			'street'       => mb_substr( (string) $snap['street'], 0, 120 ),
			'street_key'   => mb_substr( (string) $snap['street_key'], 0, 120 ),
			'zip'          => (string) $snap['zip'],
			'city'         => mb_substr( (string) $snap['city'], 0, 60 ),
			'seg_id'       => $snap['seg_id'],
			'snapped'      => (int) $snap['snapped'],
			'source'       => in_array( $in['source'] ?? '', array( 'live', 'typed', 'audio' ), true ) ? $in['source'] : 'live',
			'audio_id'     => ! empty( $in['audio_id'] ) ? (int) $in['audio_id'] : null,
		);
		$row = array_merge( $base, array(
			'client_id'    => $cid,
			'said'         => ZRP_Vocab::scrub( $said, $parsed['scope'], $house ), // scrubbed after the snap: only the map's number stays
			'scope'        => $parsed['scope'],
			'house_number' => $house,
			'suggested'    => implode( ',', $parsed['tags'] ),
			'status'       => $status,
			'why'          => mb_substr( (string) ( $in['why'] ?? '' ), 0, 120 ),
		) );
		list( $id, $new ) = ZRP_DB::add_note( $row );
		if ( $id && $new && '' !== $removed ) {
			$rp = ZRP_Vocab::parse( $removed, $vocab );
			ZRP_DB::add_note( array_merge( $base, array(
				'client_id' => '' !== $cid ? substr( $cid, 0, 37 ) . '-r' : '', 'said' => ZRP_Vocab::scrub( $removed, $rp['scope'], '' ), 'scope' => $rp['scope'],
				'house_number' => '', 'suggested' => implode( ',', $rp['tags'] ), 'status' => 'retracted', 'why' => 'corrected in the same sentence', 'retract_of' => $id,
			) ) );
		}
		$code_id = 0;
		if ( $id && $new && $codes && ZRP_Codes::ready() ) {
			foreach ( $codes as $cd ) {
				$code_id = ZRP_Codes::add( array( 'kind' => $cd[0], 'code' => $cd[1], 'street' => $base['street'], 'street_key' => $base['street_key'], 'zip' => $base['zip'],
					'lat' => $lat, 'lng' => $lng, 'source' => 'audio' === $base['source'] ? 'audio' : 'voice', 'note_id' => $id, 'created_by' => (int) $in['uid'],
					'label' => '' !== $base['street'] ? $base['street'] : '' ) ) ?: $code_id;
			}
		}
		if ( $id && $new && $said_pre !== $said && ZRP_Codes::vault_on() && ZRP_Codes::has_vault_column() ) {
			$vids = array();
			foreach ( ZRP_Codes::spans( $said_pre ) as $sp ) {
				$vids[] = ZRP_Codes::keep_id( $sp, ZRP_Codes::vault_scope( $base['street_key'], $base['zip'] ) );
			}
			foreach ( $vids as $vid ) {
				$inf = $vid ? ZDZ_Sealed::info( (int) $vid, (int) $in['uid'] ) : null;
				if ( ! $inf ) {
					continue;
				}
				global $wpdb;
				$wpdb->insert( ZRP_Codes::t_codes(), array( 'label' => mb_substr( (string) $base['street'], 0, 80 ), 'kind' => (string) $inf['kind'], 'street' => $base['street'],
					'street_key' => $base['street_key'], 'zip' => $base['zip'], 'lat' => null !== $lat ? round( $lat, 7 ) : null, 'lng' => null !== $lng ? round( $lng, 7 ) : null,
					'sealed' => '', 'digits' => 0, 'source' => 'audio' === $base['source'] ? 'audio' : 'voice', 'note_id' => $id, 'vault_id' => (int) $vid,
					'created_by' => (int) $in['uid'], 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
				$code_id = (int) $wpdb->insert_id ?: $code_id;
			}
		}
		return array( 'id' => (int) $id, 'new' => (bool) $new, 'negated' => $parsed['negated'], 'why_snap' => (string) $snap['why'], 'removed' => $removed, 'code' => $code_id );
	}

	public static function note_update( WP_REST_Request $req ) {
		$me = get_current_user_id();
		$n  = ZRP_DB::get_note( (int) $req['id'] );
		if ( ! $n ) {
			return self::err( 'not_found', 'That note was not found.', 404 );
		}
		if ( (int) $n['user_id'] !== $me && ! zrp_user_is_admin( $me ) ) {
			return self::err( 'forbidden', 'Only the person who said it, or an administrator, can change that note.', 403 );
		}
		$action = (string) $req->get_param( 'op' );
		if ( ! in_array( $action, array( 'confirm', 'discard', 'update', 'retract', 'restore' ), true ) ) {
			return self::err( 'bad_action', 'Confirm, discard, update, retract or restore.' );
		}
		$vocab  = ZRP_Vocab::vocabulary();
		if ( 'retract' === $action ) {
			// "scratch that" -- taken back, kept 30 days for an Undo, never counted
			if ( 'confirmed' === $n['status'] ) {
				return self::err( 'confirmed', 'That note was already kept; discard it on the Review list instead.', 409 );
			}
			$ok = ZRP_DB::set_status( (int) $n['id'], 'retracted', $me, array(), $vocab, array( 'why' => mb_substr( sanitize_text_field( (string) ( $req->get_param( 'why' ) ?: '"scratch that" right after it' ) ), 0, 120 ) ) );
			return $ok ? rest_ensure_response( self::note_out( ZRP_DB::get_note( (int) $n['id'] ), $vocab ) ) : self::err( 'db', 'The note could not be saved.', 500 );
		}
		if ( 'restore' === $action ) {
			if ( 'retracted' !== $n['status'] ) {
				return self::err( 'not_retracted', 'Only words a correction took back can be put back.', 409 );
			}
			$p  = ZRP_Vocab::parse( (string) $n['said'], $vocab );
			$ok = ZRP_DB::set_status( (int) $n['id'], 'pending', $me, array(), $vocab, array( 'why' => '', 'suggested' => implode( ',', $p['tags'] ) ) );
			return $ok ? rest_ensure_response( self::note_out( ZRP_DB::get_note( (int) $n['id'] ), $vocab ) ) : self::err( 'db', 'The note could not be saved.', 500 );
		}
		$fields = array();
		$scope  = (string) $req->get_param( 'scope' );
		$side   = (string) $req->get_param( 'side' );
		if ( '' !== $scope && in_array( $scope, ZRP_Vocab::SCOPES, true ) && ( $scope !== $n['scope'] || ( '' !== $side && in_array( $side, array( 'left', 'right' ), true ) ) ) ) {
			// a new scope (or side) is re-snapped from where it was said: a house number only for "this house"
			$snap   = ZRP_Snap::snap( array( 'lat' => (float) $n['lat'], 'lng' => (float) $n['lng'], 'heading' => $n['heading'], 'scope' => $scope, 'side' => in_array( $side, array( 'left', 'right' ), true ) ? $side : '' ) );
			$fields = array( 'scope' => $scope, 'side' => (string) $snap['side'], 'house_number' => 'house' === $scope ? (string) $snap['house_number'] : '' );
			if ( $snap['snapped'] ) {
				$fields += array( 'street' => mb_substr( $snap['street'], 0, 120 ), 'street_key' => mb_substr( $snap['street_key'], 0, 120 ), 'zip' => $snap['zip'], 'city' => mb_substr( $snap['city'], 0, 60 ), 'seg_id' => $snap['seg_id'], 'snapped' => 1 );
			}
			$fields['said'] = ZRP_Vocab::scrub( $n['said'], $scope, $fields['house_number'] ); // a number kept for "this house" goes when the scope widens
		}
		if ( 'discard' === $action ) {
			$ok = ZRP_DB::set_status( (int) $n['id'], 'discarded', $me, array(), $vocab, $fields );
		} else {
			$tags = $req->has_param( 'tags' ) ? (array) $req->get_param( 'tags' ) : explode( ',', (string) $n['suggested'] );
			$tags = array_values( array_unique( array_filter( array_map( array( 'ZRP_Vocab', 'slug' ), array_filter( $tags, 'is_scalar' ) ), function ( $t ) use ( $vocab ) { return isset( $vocab[ $t ] ); } ) ) );
			if ( 'update' === $action ) {
				$fields['suggested'] = implode( ',', $tags );
				$ok = false !== $GLOBALS['wpdb']->update( ZRP_DB::t_notes(), $fields, array( 'id' => (int) $n['id'] ) );
			} else {
				$ok = ZRP_DB::set_status( (int) $n['id'], 'confirmed', $me, $tags, $vocab, $fields + array( 'suggested' => implode( ',', $tags ) ) );
			}
		}
		if ( ! $ok ) {
			return self::err( 'db', 'The note could not be saved.', 500 );
		}
		return rest_ensure_response( self::note_out( ZRP_DB::get_note( (int) $n['id'] ), $vocab ) );
	}

	public static function notes_list( WP_REST_Request $req ) {
		$me     = get_current_user_id();
		$admin  = zrp_user_is_admin( $me );
		$status = (string) $req->get_param( 'status' );
		$where  = array( 'status' => in_array( $status, array( 'pending', 'confirmed', 'retracted' ), true ) ? array( $status ) : array( 'pending', 'confirmed' ) );
		// confirmed notes are every holder's map; anything pending is the person's own (the admin tier: anyone's, to tidy)
		$mine     = (bool) $req->get_param( 'mine' );
		$everyone = ! $mine && ( 'confirmed' === $status || $admin );
		if ( ! $everyone ) {
			$where['user'] = $me;
		}
		$box = array_map( 'floatval', array_filter( explode( ',', (string) $req->get_param( 'box' ) ), 'is_numeric' ) );
		if ( 4 === count( $box ) && $box[2] > $box[0] && $box[3] > $box[1] && $box[2] - $box[0] <= 0.5 && $box[3] - $box[1] <= 0.5 ) {
			$where['box'] = $box;
		}
		if ( $req->get_param( 'drive' ) ) {
			$d = ZRP_DB::get_drive( (int) $req->get_param( 'drive' ) );
			if ( ! $d || ( (int) $d['user_id'] !== $me && ! $admin ) ) {
				return self::err( 'forbidden', 'That is someone else\'s drive.', 403 ); // a colleague's drive is not rebuilt from notes
			}
			$where['drive'] = (int) $d['id'];
		}
		$v    = ZRP_Vocab::vocabulary();
		$rows = ZRP_DB::notes( $where, 1000 );
		return rest_ensure_response( array( 'notes' => array_map( function ( $n ) use ( $v ) { return self::note_out( $n, $v ); }, $rows ) ) );
	}

	/* ------------------------------------------------------------------ sealed gate codes */

	public static function codes_list( WP_REST_Request $req ) {
		$me = get_current_user_id();
		return rest_ensure_response( array( 'ready' => ZRP_Codes::ready(), 'codes' => array_map( function ( $c ) use ( $me ) { return ZRP_Codes::out( $c, $me ); }, ZRP_Codes::all() ) ) );
	}

	/** POST codes {kind, label, code, note_id | lat+lng}: a typed code, sealed at once; placed on a note's street or a point. */
	public static function code_add( WP_REST_Request $req ) {
		$me   = get_current_user_id();
		$code = ZRP_Codes::normalize_code( (string) $req->get_param( 'code' ) );
		if ( null === $code ) {
			return self::err( 'bad_code', 'A code is 3 to 16 digits (with # or * if the keypad needs them).' );
		}
		if ( ! ZRP_Codes::ready() ) {
			return self::err( 'no_sodium', 'This site cannot seal codes (libsodium is missing), so it will not keep one.', 500 );
		}
		$at = array();
		if ( $req->get_param( 'note_id' ) ) {
			$n = ZRP_DB::get_note( (int) $req->get_param( 'note_id' ) );
			if ( ! $n || ( (int) $n['user_id'] !== $me && ! zrp_user_is_admin( $me ) ) ) {
				return self::err( 'forbidden', 'That note is not yours.', 403 );
			}
			$at = array( 'street' => $n['street'], 'street_key' => $n['street_key'], 'zip' => $n['zip'], 'lat' => $n['lat'], 'lng' => $n['lng'] );
		} else {
			$lat = self::num( $req->get_param( 'lat' ), -90, 90 );
			$lng = self::num( $req->get_param( 'lng' ), -180, 180 );
			if ( null === $lat || null === $lng ) {
				return self::err( 'no_place', 'Say where the gate is: from a note, or where you are.' );
			}
			$sn = ZRP_Snap::snap( array( 'lat' => $lat, 'lng' => $lng, 'heading' => null, 'scope' => 'here', 'side' => '' ) );
			$at = array( 'street' => $sn['street'], 'street_key' => $sn['street_key'], 'zip' => $sn['zip'], 'lat' => $lat, 'lng' => $lng );
		}
		$id = ZRP_Codes::add( $at + array( 'kind' => (string) ( $req->get_param( 'kind' ) ?: 'gate' ), 'code' => $code, 'label' => (string) $req->get_param( 'label' ),
			'source' => 'typed', 'note_id' => $req->get_param( 'note_id' ) ? (int) $req->get_param( 'note_id' ) : null, 'created_by' => $me ) );
		if ( ! $id ) {
			return self::err( 'db', 'The code could not be saved.', 500 );
		}
		return rest_ensure_response( ZRP_Codes::out( ZRP_Codes::get( $id ), $me ) );
	}

	/** POST codes/<id>/reveal: the code, once, to someone who may open it now; every opening is written down. */
	public static function code_reveal( WP_REST_Request $req ) {
		$me = get_current_user_id();
		$c  = ZRP_Codes::get( (int) $req['id'] );
		if ( ! $c ) {
			return self::err( 'not_found', 'That code was not found.', 404 );
		}
		$why = ZRP_Codes::why_not( $c, $me );
		if ( '' !== $why ) {
			return self::err( 'not_today', 'This code opens for the administrators and for whoever is walking a route there today.', 403 );
		}
		$plain = ZRP_Codes::reveal_plain( $c, $me );   // from the theme's vault (which logs it), or this app's box before the move
		if ( null === $plain ) {
			return self::err( 'cannot_open', 'This code can no longer be opened (the site\'s keys changed, or the opening could not be written down). Try again, or ask for it again and add it anew.', 500 );
		}
		// every opening written down: no log line, no code; a vault code was logged by ZDZ_Sealed::open()
		if ( (int) ( $c['vault_id'] ?? 0 ) <= 0 && ! ZRP_Codes::log_view( (int) $c['id'], $me, zrp_user_is_admin( $me ) ? 'admin' : ZRP_Codes::assigned_route( $c, $me ) ) ) {
			return self::err( 'not_logged', 'The opening could not be written down, so the code stays sealed. Try again.', 500 );
		}
		nocache_headers();
		return rest_ensure_response( array( 'id' => (int) $c['id'], 'code' => $plain, 'kind' => (string) $c['kind'], 'street' => (string) $c['street'] ) );
	}

	public static function code_delete( WP_REST_Request $req ) {
		global $wpdb;
		$me = get_current_user_id();
		$c  = ZRP_Codes::get( (int) $req['id'] );
		if ( ! $c ) {
			return self::err( 'not_found', 'That code was not found.', 404 );
		}
		if ( (int) $c['created_by'] !== $me && ! zrp_user_is_admin( $me ) ) {
			return self::err( 'forbidden', 'Only the person who added it, or an administrator, can delete a code.', 403 );
		}
		$wpdb->delete( ZRP_Codes::t_codes(), array( 'id' => (int) $c['id'] ) );
		return rest_ensure_response( array( 'id' => (int) $c['id'], 'deleted' => true ) );
	}
}
