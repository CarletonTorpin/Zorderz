<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if ( class_exists( 'ZSP_Bridge' ) ) return;

class ZSP_Bridge {
	public static function is_available() { return class_exists( 'ZDZ_User_Media' ) && defined( 'ZSP_VERSION' ); }

	public static function get_user_sketches( $user_id, $limit = 20 ) {
		if ( ! class_exists( 'ZDZ_User_Media' ) ) return [];
		return array_map( function($s) {
			return [ 'id'=>(int)$s['id'], 'title'=>$s['title'], 'thumbnail_url'=>$s['thumbnail_url']?:$s['file_url'],
				'file_url'=>$s['file_url'], 'has_strokes'=>!empty($s['canvas_data']), 'created_at'=>$s['created_at'] ];
		}, ZDZ_User_Media::get_user_media( (int) $user_id, [ 'media_type'=>'sketch', 'source_app'=>'zdz-sketch-pad', 'limit'=>$limit ] ) );
	}

	public static function get_sketch( $media_id ) {
		if ( ! class_exists( 'ZDZ_User_Media' ) ) return null;
		$item = ZDZ_User_Media::get( (int) $media_id );
		if ( ! $item || $item['media_type'] !== 'sketch' ) return null;
		return [ 'id'=>(int)$item['id'], 'title'=>$item['title'], 'file_url'=>$item['file_url'],
			'canvas_data'=>$item['canvas_data'], 'privacy'=>$item['privacy'], 'created_at'=>$item['created_at'] ];
	}

	public static function get_count( $user_id ) {
		global $wpdb;
		if ( ! class_exists( 'ZDZ_User_Media' ) ) return 0;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zdz_user_media WHERE user_id=%d AND media_type='sketch' AND source_app='zdz-sketch-pad' AND status='active'", (int) $user_id ) );
	}
}
