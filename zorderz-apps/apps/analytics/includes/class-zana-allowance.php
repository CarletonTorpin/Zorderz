<?php
/**
 * ZANA_Allowance — each person's daily Ai allowance for the chat.
 *
 * The site's monthly Ai budget is the router's (ZDZ_Core_LLM: no budget set means nothing is sent at all) and comes
 * first. On top of it a business may give each person's chat a daily line (Settings → Analytics, option
 * `zana_ai_allowance`, dollars; the filter of the same name). Core ships none (0 = no daily stop): the monthly budget is
 * the floor, and a daily line is the business's choice.
 *
 *   - owner-level accounts have no daily stop; background work (no person) is charged to no one;
 *   - the day is the site's own, midnight to midnight;
 *   - two user meta rows per person, `zana_ai_day` (the date) and `zana_ai_day_milli` (thousandths of a dollar): one
 *     number a person a day, no words;
 *   - checked before each call; after it, its real cost (or what a failed call cost, or the router's per-call cap when
 *     it ended in an exception) is added by one SQL increment, so two calls at once both count. Two calls started at
 *     the same moment can both pass the check, so a day can end over by the calls already under way when the line was
 *     crossed.
 *
 * Enforced here, on the server; the chat page only says it.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Allowance {

	const OPTION     = 'zana_ai_allowance';
	const MAX_USD    = 50.0;
	const META       = 'zana_ai_day';         // the day the figure is for, Y-m-d
	const META_MILLI = 'zana_ai_day_milli';   // thousandths of a dollar spent that day

	public static function boot(): void {
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'zdz_core_settings_rows', array( __CLASS__, 'render_setting_row' ) );
	}

	public static function register_setting(): void {
		register_setting( 'zdz_core_settings_group', self::OPTION, array(
			'type'              => 'number',
			'default'           => 0,
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
		) );
	}

	/** The line, on Zorderz → Settings. */
	public static function render_setting_row(): void {
		$v = self::allowance_usd();
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( self::OPTION ); ?>"><?php esc_html_e( 'Chat: daily Ai allowance per person (USD)', 'zorderz' ); ?></label></th>
			<td>
				<input type="number" min="0" max="<?php echo esc_attr( (string) self::MAX_USD ); ?>" step="0.5" id="<?php echo esc_attr( self::OPTION ); ?>" name="<?php echo esc_attr( self::OPTION ); ?>" value="<?php echo esc_attr( (string) $v ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( '0 = no daily stop. Admin-level accounts have none either. The site\'s monthly Ai budget (Zorderz → Ai usage) always applies first.', 'zorderz' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/** The daily line in dollars; 0 = no daily stop. */
	public static function allowance_usd(): float {
		$v = function_exists( 'get_option' ) ? get_option( self::OPTION, 0 ) : 0;
		$v = is_numeric( $v ) ? (float) $v : 0.0;
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( self::OPTION, $v );
			$v = is_numeric( $f ) ? (float) $f : $v;
		}
		return max( 0.0, min( self::MAX_USD, $v ) );
	}

	public static function sanitize( $v ): float {
		$v = is_numeric( $v ) ? (float) $v : 0.0;
		return round( max( 0.0, min( self::MAX_USD, $v ) ), 2 );
	}

	/** Tests set the day: callable() => 'Y-m-d'. */
	public static $today = null;

	/** The site's own date, Y-m-d. */
	public static function today(): string {
		if ( is_callable( self::$today ) ) {
			return (string) call_user_func( self::$today );
		}
		return function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/** An owner-level account that is not the shared device. */
	public static function is_owner( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
			return 'admin' === ZDZ_Capabilities::tier_of( $uid );
		}
		return function_exists( 'user_can' ) && user_can( $uid, 'manage_options' );
	}

	/** What this person's chat has spent today, in thousandths of a dollar. */
	public static function spent_milli( int $uid ): int {
		if ( $uid <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return 0;
		}
		if ( (string) get_user_meta( $uid, self::META, true ) !== self::today() ) {
			return 0;
		}
		return max( 0, (int) get_user_meta( $uid, self::META_MILLI, true ) );
	}

	/** Add a call's cost (thousandths) to today's figure. */
	public static function add_milli( int $uid, int $milli ): void {
		if ( $uid <= 0 || $milli <= 0 || ! function_exists( 'update_user_meta' ) ) {
			return;
		}
		$today = self::today();
		if ( (string) get_user_meta( $uid, self::META, true ) !== $today ) {
			update_user_meta( $uid, self::META_MILLI, 0 );
			update_user_meta( $uid, self::META, $today );
		}
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && ! empty( $wpdb->usermeta ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			// one statement, so two calls finishing at once both count
			$n = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS UNSIGNED) + %d WHERE user_id = %d AND meta_key = %s",
				$milli,
				$uid,
				self::META_MILLI
			) );
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $uid, 'user_meta' );
			}
			if ( $n ) {
				return;
			}
		}
		update_user_meta( $uid, self::META_MILLI, self::spent_milli( $uid ) + $milli );
	}

	/**
	 * May this person's chat make another call today? null when yes; otherwise the sentence to show. Background work
	 * (no person), owner-level accounts and a site with no daily line have no daily stop.
	 */
	public static function refusal( int $uid ): ?string {
		if ( $uid <= 0 || self::is_owner( $uid ) ) {
			return null;
		}
		$cap = self::allowance_usd();
		if ( $cap <= 0 ) {
			return null;
		}
		if ( self::spent_milli( $uid ) >= (int) round( $cap * 1000 ) ) {
			return __( 'You\'ve used today\'s Ai allowance for the chat. It starts again at midnight; an admin can raise it in Settings.', 'zorderz' );
		}
		return null;
	}

	/** Roughly how much of today's allowance is used, 0..100 (for the chat's note). */
	public static function used_percent( int $uid ): int {
		$cap = self::allowance_usd();
		if ( $cap <= 0 || self::is_owner( $uid ) ) {
			return 0;
		}
		return (int) min( 100, floor( self::spent_milli( $uid ) / ( $cap * 10 ) ) );
	}
}
