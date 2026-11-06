<?php
/**
 * ZANA_Starters — the starter questions the chat offers on its welcome screen, per role.
 *
 * Each Core role has its own list (option `zana_role_prompts_<bucket>`, one question per line, Zorderz → Chat
 * settings; filter `zana_role_starter_prompts`). Core ships a few neutral questions any business can ask; a business
 * writes its own (its voice or knowledge pack fills the options). The shared device gets none (the chat is not offered
 * there). GET {ns}/analytics/starters returns the signed-in person's list.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Starters {

	const OPTION_PREFIX = 'zana_role_prompts_';
	const MAX           = 200;

	/** The buckets and the Core role slugs that map to them. An unknown role gets the most limited bucket. */
	const BUCKETS = array(
		'owner'    => array( 'zdz_owner' ),
		'admin'    => array( 'zdz_admin', 'administrator' ),
		'sales'    => array( 'zdz_sales' ),
		'operator' => array( 'zdz_operator' ),
		'shop'     => array( 'zdz_mfg' ),
		'field'    => array( 'zdz_tech' ),
	);

	public static function labels(): array {
		return array(
			'owner'    => __( 'Owner', 'zorderz' ),
			'admin'    => __( 'Administrator', 'zorderz' ),
			'sales'    => __( 'Sales', 'zorderz' ),
			'operator' => __( 'Operator', 'zorderz' ),
			'shop'     => __( 'Shop', 'zorderz' ),
			'field'    => __( 'Field', 'zorderz' ),
		);
	}

	/** A role slug's bucket ('field', the most limited, for any role it does not know). */
	public static function bucket_for_roles( array $roles ): string {
		foreach ( self::BUCKETS as $b => $slugs ) {
			if ( array_intersect( $slugs, $roles ) ) {
				return $b;
			}
		}
		return 'field';
	}

	/** Core's neutral questions for a bucket. */
	public static function defaults( string $bucket ): array {
		$all = array(
			__( 'What changed in the business this week compared with last week?', 'zorderz' ),
			__( 'Which estimates are still open, oldest first?', 'zorderz' ),
			__( 'Who am I waiting to hear back from?', 'zorderz' ),
		);
		$by  = array(
			'owner'    => array( __( 'How do this month\'s sales compare with the same month last year?', 'zorderz' ), __( 'Which kinds of work brought in the most this quarter?', 'zorderz' ), __( 'Where did our new customers come from this month?', 'zorderz' ) ),
			'admin'    => array( __( 'Which invoices are unpaid and past due?', 'zorderz' ), __( 'What is scheduled for tomorrow?', 'zorderz' ), __( 'Which leads have not been contacted yet?', 'zorderz' ) ),
			'sales'    => array( __( 'Which of my estimates should I follow up on today?', 'zorderz' ), __( 'How many jobs have I sold this month?', 'zorderz' ), __( 'Who needs a reply from me?', 'zorderz' ) ),
			'operator' => array( __( 'What is on the schedule this week?', 'zorderz' ), __( 'Which jobs are waiting on parts or materials?', 'zorderz' ), __( 'Which customers asked to reschedule?', 'zorderz' ) ),
			'shop'     => array( __( 'What is in the work queue today?', 'zorderz' ), __( 'Which jobs are due this week?', 'zorderz' ), __( 'Who can help on a job tomorrow?', 'zorderz' ) ),
			'field'    => array( __( 'What is on my schedule today?', 'zorderz' ), __( 'How many hours did I work this week?', 'zorderz' ), __( 'Who can I ask about a job I\'m on?', 'zorderz' ) ),
		);
		return array_merge( $by[ $bucket ] ?? $by['field'], 'field' === $bucket || 'shop' === $bucket ? array() : $all );
	}

	/** The active list for a bucket: the business's own when set, else Core's. */
	public static function get_prompts( string $bucket ): array {
		$bucket = isset( self::BUCKETS[ $bucket ] ) ? $bucket : 'field';
		$raw    = function_exists( 'get_option' ) ? (string) get_option( self::OPTION_PREFIX . $bucket, '' ) : '';
		$list   = '' !== trim( $raw ) ? self::lines( $raw ) : self::defaults( $bucket );
		if ( function_exists( 'apply_filters' ) ) {
			$f    = apply_filters( 'zana_role_starter_prompts', $list, $bucket );
			$list = is_array( $f ) ? $f : $list;
		}
		return array_slice( array_values( array_unique( array_filter( array_map( function ( $s ) { return mb_substr( trim( (string) $s ), 0, 200 ); }, $list ), 'strlen' ) ) ), 0, self::MAX );
	}

	/** One question per line, cleaned. */
	public static function lines( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $l ) {
			$l = trim( wp_strip_all_tags( (string) $l ) );
			if ( '' !== $l ) {
				$out[] = mb_substr( $l, 0, 200 );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, self::MAX );
	}

	public static function sanitize( $v ): string {
		return implode( "\n", self::lines( (string) $v ) );
	}

	/** The signed-in person's list (none for the shared device). */
	public static function for_user( int $uid ): array {
		if ( $uid <= 0 || ( class_exists( 'ZANA_LLM_Client' ) && ZANA_LLM_Client::is_shared_device( $uid ) ) ) {
			return array();
		}
		$u = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		return self::get_prompts( self::bucket_for_roles( $u ? (array) $u->roles : array() ) );
	}
}
