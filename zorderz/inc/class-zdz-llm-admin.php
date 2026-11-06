<?php
/**
 * ZDZ_LLM_Admin -- two pages under Zorderz, the admin tier only.
 *
 *   Ai usage   this month against the business's monthly lines (the site's apps, and everything on the provider
 *              account), the share read from cache, pictures sent; by app, kind of call, model and day; tokens by app;
 *              the latest calls one by one; fallbacks and refusals; who answers each kind of call (read-only), and
 *              which kinds, if any, an admin has pointed at Poe or at an OpenAI-compatible entry. Numbers only: the
 *              ledger holds no text. Below them, Setup: the three models, their prices, the region and the monthly
 *              budget -- what a connections pack would otherwise set.
 *   Ai checks  runs the golden sets (data/llm-golden/, made-up inputs only) through the real router on the native
 *              provider, one case per request so no request outlasts the host's window, and shows each kind's score,
 *              time and cost. The most a run can cost is shown before the button is pressed, and a run stops at
 *              RUN_CAP. What it decides: the quick-model time line per kind of call ("Use these time lines").
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_LLM_Admin {

	const USAGE_PAGE   = 'zdz-llm-usage';
	const CHECKS_PAGE  = 'zdz-llm-checks';
	const LAST_OPTION  = 'zdz_llm_checks_last';
	const RUN_CAP      = 3.50;            // a run of the checks stops here (per person, per hour)
	const CHECK_CALLER = 'zdz-llm-checks';
	const MAX_PRICE    = 10000.0;         // USD per million tokens: anything above is a typing slip

	public static function boot() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'add_pages' ), 30 );
		add_action( 'wp_ajax_zdz_llm_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'wp_ajax_zdz_llm_check_done', array( __CLASS__, 'ajax_done' ) );
		add_action( 'admin_post_zdz_llm_lines', array( __CLASS__, 'handle_lines' ) );
		add_action( 'admin_post_zdz_llm_setup', array( __CLASS__, 'handle_setup' ) );
	}

	public static function add_pages() {
		add_submenu_page( 'zdz-core-settings', __( 'Ai usage', 'zorderz' ), __( 'Ai usage', 'zorderz' ), 'manage_options', self::USAGE_PAGE, array( __CLASS__, 'render_usage' ) );
		add_submenu_page( 'zdz-core-settings', __( 'Ai checks', 'zorderz' ), __( 'Ai checks', 'zorderz' ), 'manage_options', self::CHECKS_PAGE, array( __CLASS__, 'render_checks' ) );
	}

	/** The admin tier, and never the shared device. */
	public static function allowed() {
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $uid <= 0 || ! current_user_can( 'manage_options' ) || ZDZ_Core_LLM::is_kiosk( $uid ) ) {
			return false;
		}
		return class_exists( 'ZDZ_Hierarchy' ) ? ZDZ_Hierarchy::is_admin( $uid ) : true;
	}

	private static function money( $usd ) {
		return '$' . number_format( (float) $usd, 2 );
	}

	/** Light and dark: the system default and an explicit dark both reach the dark block. */
	public static function styles() {
		return '<style>
.zdz-llm{--l-bg:#ffffff;--l-fg:#1f2933;--l-mute:#5e6976;--l-line:#d9dee4;--l-soft:#f3f5f8;--l-bar:#3569b5;--l-bar2:#227a8c;--l-warn-bg:#fff4d6;--l-warn-fg:#6b4a00;--l-bad-bg:#fde8e8;--l-bad-fg:#8a1c1c;--l-ok-bg:#e3f4ea;--l-ok-fg:#14532d;--l-btn-bg:#3569b5;--l-btn-fg:#ffffff;
 background:var(--l-bg);color:var(--l-fg);border:1px solid var(--l-line);border-radius:12px;padding:20px 24px;margin:16px 20px 24px 0;max-width:1100px;font-size:14px;line-height:1.45}
@media (prefers-color-scheme:dark){html:not([data-theme="light"]):not([data-theme="sunlight"]) .zdz-llm{--l-bg:#161b22;--l-fg:#e6eaef;--l-mute:#a3adb8;--l-line:#2f3742;--l-soft:#1f2630;--l-bar:#6f9fe0;--l-bar2:#5fb6c6;--l-warn-bg:#3a2f12;--l-warn-fg:#f3d58a;--l-bad-bg:#3d1717;--l-bad-fg:#f5b5b5;--l-ok-bg:#12321f;--l-ok-fg:#9fe0b4;--l-btn-bg:#6f9fe0;--l-btn-fg:#0b1a2e}}
html[data-theme="dark"] .zdz-llm,.zdz-llm.zdz-llm-dark{--l-bg:#161b22;--l-fg:#e6eaef;--l-mute:#a3adb8;--l-line:#2f3742;--l-soft:#1f2630;--l-bar:#6f9fe0;--l-bar2:#5fb6c6;--l-warn-bg:#3a2f12;--l-warn-fg:#f3d58a;--l-bad-bg:#3d1717;--l-bad-fg:#f5b5b5;--l-ok-bg:#12321f;--l-ok-fg:#9fe0b4;--l-btn-bg:#6f9fe0;--l-btn-fg:#0b1a2e}
.zdz-llm h1{font-size:22px;margin:0 0 4px;color:var(--l-fg)}.zdz-llm h2{font-size:16px;margin:22px 0 8px;color:var(--l-fg)}
.zdz-llm p{margin:6px 0;color:var(--l-fg)}.zdz-llm .mute{color:var(--l-mute)}
.zdz-llm .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:14px 0}
.zdz-llm .tile{background:var(--l-soft);border:1px solid var(--l-line);border-radius:10px;padding:12px 14px}
.zdz-llm .tile b{display:block;font-size:22px;font-variant-numeric:tabular-nums}.zdz-llm .tile span{color:var(--l-mute);font-size:13px}
.zdz-llm .meter{height:10px;border-radius:5px;background:var(--l-line);overflow:hidden;margin-top:8px}.zdz-llm .meter i{display:block;height:100%;background:var(--l-bar)}
.zdz-llm .meter.all i{background:var(--l-bar2)}
.zdz-llm table{border-collapse:collapse;width:100%;table-layout:auto;margin:4px 0 8px;font-variant-numeric:tabular-nums}
.zdz-llm th,.zdz-llm td{text-align:left;padding:6px 10px;border-bottom:1px solid var(--l-line);color:var(--l-fg)}.zdz-llm th{color:var(--l-mute);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
.zdz-llm td.n,.zdz-llm th.n{text-align:right;white-space:nowrap}
.zdz-llm .cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:8px 28px}
.zdz-llm .pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:12px;font-weight:600}
.zdz-llm .warn{background:var(--l-warn-bg);color:var(--l-warn-fg)}.zdz-llm .bad{background:var(--l-bad-bg);color:var(--l-bad-fg)}.zdz-llm .good{background:var(--l-ok-bg);color:var(--l-ok-fg)}
.zdz-llm .note{background:var(--l-warn-bg);color:var(--l-warn-fg);border-radius:8px;padding:10px 12px;margin:10px 0}
.zdz-llm .btn{display:inline-block;min-height:40px;padding:8px 18px;border-radius:8px;border:0;background:var(--l-btn-bg);color:var(--l-btn-fg);font-weight:600;font-size:14px;cursor:pointer}
.zdz-llm .btn[disabled]{opacity:.55;cursor:default}.zdz-llm .btn.ghost{background:transparent;color:var(--l-fg);border:1px solid var(--l-line)}
.zdz-llm .tscroll{overflow-x:auto;max-width:100%}.zdz-llm td.n .mute{display:block;font-size:12px}
.zdz-llm .status{margin:10px 0;min-height:20px;color:var(--l-mute)}
.zdz-llm input[type=text],.zdz-llm input[type=number]{min-height:36px;background:var(--l-bg);color:var(--l-fg);border:1px solid var(--l-line);border-radius:6px;padding:4px 8px;max-width:100%}
.zdz-llm input[type=number]{width:7.5em}.zdz-llm label{display:block;font-weight:600;margin:10px 0 4px}
</style>';
	}

	/* ================================================================== Ai usage */

	public static function render_usage() {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		ZDZ_LLM_Ledger::maybe_install();
		$rows  = ZDZ_LLM_Ledger::rows_since( ZDZ_LLM_Ledger::month_start() );
		$saved = isset( $_GET['saved'] ) ? (int) $_GET['saved'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display flag only
		$form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'zdz_llm_setup', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="zdz_llm_setup">';
		echo self::usage_html( ZDZ_LLM_Ledger::summary( $rows ), ZDZ_LLM_Ledger::month_to_date(), gmdate( 'F Y' ), $form, $saved ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from numbers and code words, escaped inside
	}

	/** The page body (pure: the suites call it with made-up figures). */
	public static function usage_html( array $s, $mtd, $month_label, $setup_form = '', $saved = null ) {
		$b   = ZDZ_Core_LLM::budgets();
		$app = is_array( $mtd ) ? $mtd['app'] : $s['app_usd'];
		$all = is_array( $mtd ) ? $mtd['all'] : $s['usd'];
		$h   = self::styles() . '<div class="wrap"><div class="zdz-llm" data-zdz-llm="usage">';
		$h  .= '<h1>' . esc_html__( 'Ai usage', 'zorderz' ) . '</h1><p class="mute">' . esc_html( $month_label ) . ' ' . esc_html__( '(UTC), from the Ai ledger. Numbers only: the ledger keeps no questions, answers or names.', 'zorderz' ) . '</p>';
		if ( null !== $saved ) {
			$h .= '<p class="note">' . esc_html__( 'Setup saved.', 'zorderz' ) . '</p>';
		}
		if ( ! is_array( $mtd ) ) {
			$h .= '<p class="note">' . esc_html__( 'The ledger could not be read just now, so every Ai call is refused until it can.', 'zorderz' ) . '</p>';
		}
		if ( ! $b['set'] ) {
			$h .= '<p class="note">' . esc_html__( 'No monthly budget is set, so every Ai call is refused. Set one under Setup below.', 'zorderz' ) . '</p>';
		}
		$h .= '<div class="tiles">';
		if ( $b['set'] ) {
			$pa  = $b['app'] > 0 ? min( 100, $app / $b['app'] * 100 ) : 100;
			$pl  = $b['ceiling'] > 0 ? min( 100, $all / $b['ceiling'] * 100 ) : 100;
			$h  .= '<div class="tile"><span>' . esc_html__( 'This site\'s apps this month', 'zorderz' ) . '</span><b>' . esc_html( self::money( $app ) ) . '</b><span>' . esc_html( sprintf( __( 'of %1$s (%2$s%%)', 'zorderz' ), self::money( $b['app'] ), number_format( $pa, 0 ) ) ) . '</span><div class="meter" role="img" aria-label="' . esc_attr( sprintf( __( '%s%% of the site\'s budget', 'zorderz' ), number_format( $pa, 0 ) ) ) . '"><i style="width:' . esc_attr( number_format( $pa, 1, '.', '' ) ) . '%"></i></div></div>';
			$h  .= '<div class="tile"><span>' . esc_html__( 'Everything on the Ai account', 'zorderz' ) . '</span><b>' . esc_html( self::money( $all ) ) . '</b><span>' . esc_html( sprintf( __( 'of %1$s; calls stop at %2$s', 'zorderz' ), self::money( $b['ceiling'] ), self::money( $b['all'] ) ) ) . '</span><div class="meter all" role="img" aria-label="' . esc_attr( sprintf( __( '%s%% of the ceiling', 'zorderz' ), number_format( $pl, 0 ) ) ) . '"><i style="width:' . esc_attr( number_format( $pl, 1, '.', '' ) ) . '%"></i></div></div>';
		} else {
			$h .= '<div class="tile"><span>' . esc_html__( 'This site\'s apps this month', 'zorderz' ) . '</span><b>' . esc_html( self::money( $app ) ) . '</b><span>' . esc_html__( 'no budget set', 'zorderz' ) . '</span></div>';
			$h .= '<div class="tile"><span>' . esc_html__( 'Everything on the Ai account', 'zorderz' ) . '</span><b>' . esc_html( self::money( $all ) ) . '</b><span>' . esc_html__( 'no budget set', 'zorderz' ) . '</span></div>';
		}
		$h .= '<div class="tile"><span>' . esc_html__( 'Read from cache', 'zorderz' ) . '</span><b>' . esc_html( number_format( $s['cache_share'] * 100, 0 ) ) . '%</b><span>' . esc_html__( 'of prompt tokens', 'zorderz' ) . '</span></div>';
		$h .= '<div class="tile"><span>' . esc_html__( 'Calls · pictures sent', 'zorderz' ) . '</span><b>' . esc_html( number_format( $s['calls'] ) ) . ' · ' . esc_html( number_format( $s['pictures'] ) ) . '</b><span>' . esc_html( sprintf( __( '%s answered by a fallback model', 'zorderz' ), number_format( $s['fallbacks'] ) ) ) . '</span></div>';
		$h .= '</div>';
		$h .= '<p class="mute">' . esc_html__( 'Another tool that spends on the same Ai account can report its spend here; it counts toward Everything, not toward this site\'s apps.', 'zorderz' ) . '</p>';

		$h .= '<div class="cols">';
		$dims = array( 'caller' => __( 'By app', 'zorderz' ), 'role' => __( 'By kind of call', 'zorderz' ), 'model' => __( 'By model', 'zorderz' ), 'day' => __( 'By day', 'zorderz' ) );
		foreach ( $dims as $dim => $title ) {
			$h .= '<div><h2>' . esc_html( $title ) . '</h2>' . self::table( $s['by'][ $dim ], 'day' === $dim ) . '</div>';
		}
		$h .= '</div>';

		$h .= self::tokens_html( (array) ( $s['tokens'] ?? array() ) );
		$h .= self::recent_html( (array) ( $s['recent'] ?? array() ) );

		$h .= '<h2>' . esc_html__( 'Fallbacks and refusals', 'zorderz' ) . '</h2>';
		$o  = $s['outcomes'];
		unset( $o['ok'] );
		if ( ! $o && ! $s['fallbacks'] ) {
			$h .= '<p class="mute">' . esc_html__( 'None this month.', 'zorderz' ) . '</p>';
		} else {
			$h .= '<table><thead><tr><th>' . esc_html__( 'What happened', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Calls', 'zorderz' ) . '</th></tr></thead><tbody>';
			if ( $s['fallbacks'] ) {
				$h .= '<tr><td>' . esc_html__( 'Answered by a fallback model', 'zorderz' ) . '</td><td class="n">' . esc_html( number_format( $s['fallbacks'] ) ) . '</td></tr>';
			}
			arsort( $o );
			foreach ( $o as $k => $n ) {
				$h .= '<tr><td>' . esc_html( self::outcome_words( (string) $k ) ) . '</td><td class="n">' . esc_html( number_format( $n ) ) . '</td></tr>';
			}
			$h .= '</tbody></table>';
		}

		$h .= self::roles_html();
		if ( '' !== $setup_form ) {
			$h .= self::setup_html( $setup_form );
		}
		$h .= '</div></div>';
		return $h;
	}

	/**
	 * Where the prompt tokens went, by app. A call reads its prompt once at full price (New), at a fraction of the price
	 * when the provider has it cached (Read from cache), or pays a little extra to cache it (Written). An app whose New
	 * column dwarfs its cache column is sending a prompt that changes at the start every call.
	 */
	public static function tokens_html( array $tok ) {
		$h = '<h2>' . esc_html__( 'Tokens by app', 'zorderz' ) . '</h2>';
		if ( ! $tok ) {
			return $h . '<p class="mute">' . esc_html__( 'No calls yet.', 'zorderz' ) . '</p>';
		}
		uasort( $tok, function ( $a, $b ) {
			return $b['usd'] <=> $a['usd'];
		} );
		$k  = function ( $n ) {
			return $n >= 1000000 ? number_format( $n / 1000000, 1 ) . 'M' : ( $n >= 1000 ? number_format( $n / 1000, 0 ) . 'K' : number_format( $n ) );
		};
		$h .= '<table><thead><tr><th></th><th class="n">' . esc_html__( 'Calls', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Prompt a call', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'New', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Read from cache', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Written to cache', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Output', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'From cache', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Cost a call', 'zorderz' ) . '</th></tr></thead><tbody>';
		foreach ( $tok as $app => $v ) {
			$calls  = max( 1, (int) $v['calls'] );
			$prompt = (int) $v['in'] + (int) $v['cache_read'] + (int) $v['cache_write'];
			$share  = $prompt > 0 ? $v['cache_read'] / $prompt * 100 : 0;
			$h .= '<tr><td>' . esc_html( '' === (string) $app ? '-' : (string) $app ) . '</td>'
				. '<td class="n">' . esc_html( number_format( (int) $v['calls'] ) ) . '</td>'
				. '<td class="n">' . esc_html( $k( (int) round( $prompt / $calls ) ) ) . '</td>'
				. '<td class="n">' . esc_html( $k( (int) $v['in'] ) ) . '</td>'
				. '<td class="n">' . esc_html( $k( (int) $v['cache_read'] ) ) . '</td>'
				. '<td class="n">' . esc_html( $k( (int) $v['cache_write'] ) ) . '</td>'
				. '<td class="n">' . esc_html( $k( (int) $v['out'] ) ) . '</td>'
				. '<td class="n">' . esc_html( number_format( $share, 0 ) ) . '%</td>'
				. '<td class="n">' . esc_html( self::money( $v['usd'] / $calls ) ) . '</td></tr>';
		}
		return $h . '</tbody></table><p class="mute">' . esc_html__( 'New is read at full price; Read from cache at a fraction of it; Written to cache costs a little more than New, once, so the next calls can read it. A high New column means the start of that app\'s prompt changes every call.', 'zorderz' ) . '</p>';
	}

	/**
	 * The latest calls one by one, newest first -- which call of a chat turn carries the tokens and the seconds. Numbers
	 * and code words only (no text of any call is kept anywhere).
	 */
	public static function recent_html( array $recent ) {
		$h = '<h2>' . esc_html__( 'Recent calls', 'zorderz' ) . '</h2>';
		if ( ! $recent ) {
			return $h . '<p class="mute">' . esc_html__( 'No calls yet.', 'zorderz' ) . '</p>';
		}
		$k  = function ( $n ) {
			return $n >= 1000000 ? number_format( $n / 1000000, 1 ) . 'M' : ( $n >= 1000 ? number_format( $n / 1000, 1 ) . 'K' : number_format( $n ) );
		};
		$h .= '<table><thead><tr><th>' . esc_html__( 'When (UTC)', 'zorderz' ) . '</th><th>' . esc_html__( 'App', 'zorderz' ) . '</th><th>' . esc_html__( 'Kind', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'New', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Read from cache', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Written to cache', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Output', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Coded', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Seconds', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Cost', 'zorderz' ) . '</th><th>' . esc_html__( 'Result', 'zorderz' ) . '</th></tr></thead><tbody>';
		foreach ( $recent as $r ) {
			$h .= '<tr><td>' . esc_html( substr( (string) ( $r['ts'] ?? '' ), 5, 14 ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $r['caller'] ?? '' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $r['role'] ?? '' ) ) . '</td>'
				. '<td class="n">' . esc_html( $k( (int) ( $r['in'] ?? 0 ) ) ) . '</td>'
				. '<td class="n">' . esc_html( $k( (int) ( $r['cache_read'] ?? 0 ) ) ) . '</td>'
				. '<td class="n">' . esc_html( $k( (int) ( $r['cache_write'] ?? 0 ) ) ) . '</td>'
				. '<td class="n">' . esc_html( $k( (int) ( $r['out'] ?? 0 ) ) ) . '</td>'
				. '<td class="n">' . esc_html( number_format( (int) ( $r['coded'] ?? 0 ) ) ) . '</td>'
				. '<td class="n">' . esc_html( number_format( ( (int) ( $r['ms'] ?? 0 ) ) / 1000, 1 ) ) . '</td>'
				. '<td class="n">' . esc_html( self::money( (float) ( $r['usd'] ?? 0 ) ) ) . '</td>'
				. '<td>' . esc_html( 'ok' === ( $r['outcome'] ?? '' ) ? 'ok' : self::outcome_words( (string) ( $r['outcome'] ?? '' ) ) ) . '</td></tr>';
		}
		return $h . '</tbody></table><p class="mute">' . esc_html( sprintf( __( 'The latest %d calls this month, newest first. A chat turn is often two or three calls in a row. Coded: how many private details (names, e-mails, phones, streets) the call carried as codes.', 'zorderz' ), count( $recent ) ) ) . '</p>';
	}

	private static function table( array $by, $by_day ) {
		if ( ! $by ) {
			return '<p class="mute">' . esc_html__( 'No calls yet.', 'zorderz' ) . '</p>';
		}
		if ( $by_day ) {
			krsort( $by );
		} else {
			uasort( $by, function ( $a, $b ) {
				return $b['usd'] <=> $a['usd'];
			} );
		}
		$h = '<table><thead><tr><th></th><th class="n">' . esc_html__( 'Calls', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Cost', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Avg time', 'zorderz' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $by, 0, 31, true ) as $k => $v ) {
			$h .= '<tr><td>' . esc_html( '' === (string) $k ? '-' : (string) $k ) . '</td><td class="n">' . esc_html( number_format( $v['calls'] ) ) . '</td><td class="n">' . esc_html( self::money( $v['usd'] ) ) . '</td><td class="n">' . esc_html( number_format( $v['calls'] ? $v['ms'] / $v['calls'] / 1000 : 0, 1 ) ) . ' s</td></tr>';
		}
		return $h . '</tbody></table>';
	}

	public static function outcome_words( $k ) {
		$w = array(
			'refused_money'        => __( 'Refused: would cost more than its cap', 'zorderz' ),
			'refused_month'        => __( 'Refused: the month\'s budget is used up', 'zorderz' ),
			'refused_budget'       => __( 'Refused: no monthly budget set', 'zorderz' ),
			'refused_guard'        => __( 'Refused: outside zero data retention', 'zorderz' ),
			'refused_web'          => __( 'Refused: web search with more than a question', 'zorderz' ),
			'refused_picture'      => __( 'Refused: a picture from an app that may not send one', 'zorderz' ),
			'refused_kiosk'        => __( 'Refused: the shared device', 'zorderz' ),
			'refused_caller'       => __( 'Refused: an app not registered', 'zorderz' ),
			'refused_terms'        => __( 'Refused: a provider whose terms aren\'t checked', 'zorderz' ),
			'refused_prices'       => __( 'Refused: a model with no prices', 'zorderz' ),
			'refused_model'        => __( 'Refused: no model set', 'zorderz' ),
			'refused_coder'        => __( 'Refused: private details could not be coded', 'zorderz' ),
			'refused_schema'       => __( 'Refused: private details in an answer shape', 'zorderz' ),
			'refused_time'         => __( 'Refused: no time left', 'zorderz' ),
			'refused_ledger'       => __( 'Refused: the ledger couldn\'t be read', 'zorderz' ),
			'refused_request'      => __( 'Refused: a request the Ai does not take', 'zorderz' ),
			'refused_role'         => __( 'Refused: an unknown kind of call', 'zorderz' ),
			'refused_primary_only' => __( 'Refused: primary model only, but set to another provider', 'zorderz' ),
			'bad_json'             => __( 'Answered in the wrong shape', 'zorderz' ),
			'empty'                => __( 'Answered with nothing', 'zorderz' ),
		);
		if ( isset( $w[ $k ] ) ) {
			return $w[ $k ];
		}
		if ( 0 === strpos( $k, 'error_' ) ) {
			return sprintf( __( 'Failed: %s', 'zorderz' ), str_replace( '_', ' ', substr( $k, 6 ) ) );
		}
		return str_replace( '_', ' ', $k );
	}

	/** Who answers each kind of call, read-only. */
	public static function roles_html() {
		$h  = '<h2>' . esc_html__( 'Who answers each kind of call', 'zorderz' ) . '</h2>';
		$h .= '<div class="tscroll"><table><thead><tr><th>' . esc_html__( 'Kind', 'zorderz' ) . '</th><th>' . esc_html__( 'Provider', 'zorderz' ) . '</th><th>' . esc_html__( 'Model', 'zorderz' ) . '</th><th>' . esc_html__( 'Effort', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Cap a call', 'zorderz' ) . '</th><th>' . esc_html__( 'Pictures', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Quick model under', 'zorderz' ) . '</th></tr></thead><tbody>';
		$poe = array();
		foreach ( array_keys( ZDZ_Core_LLM::ROLES ) as $role ) {
			$r    = ZDZ_Core_LLM::role( $role );
			$prov = 'anthropic' === $r['provider'] ? 'Anthropic' : ( 'poe' === $r['provider'] ? 'Poe' : sprintf( __( 'OpenAI-compatible: %s', 'zorderz' ), $r['entry_id'] ) );
			if ( 'poe' === $r['provider'] ) {
				$poe[] = $role;
			}
			$model = '' === (string) $r['model'] ? '<span class="pill bad">' . esc_html__( 'not set', 'zorderz' ) . '</span>' : esc_html( $r['model'] );
			if ( '' !== (string) $r['model'] && 'anthropic' === $r['provider'] && null === ZDZ_Core_LLM::prices_for( $r['model'] ) ) {
				$model .= ' <span class="pill bad">' . esc_html__( 'no prices', 'zorderz' ) . '</span>';
			}
			$h .= '<tr><td>' . esc_html( $role ) . '</td><td>' . esc_html( $prov ) . ( 'anthropic' === $r['provider'] ? '' : ' <span class="pill bad">' . esc_html__( 'sends data off zero retention', 'zorderz' ) . '</span>' ) . '</td><td>' . $model . '</td><td>' . esc_html( 'reason' === $role ? __( 'high with 40 s left', 'zorderz' ) : $r['effort'] ) . '</td><td class="n">' . esc_html( self::money( $r['cap_usd'] ) ) . '</td><td>' . esc_html( $r['pictures'] ? __( 'yes', 'zorderz' ) : __( 'no', 'zorderz' ) ) . '</td><td class="n">' . esc_html( (string) (int) $r['quick_line_s'] ) . ' s' . ( $r['line_measured'] ? '' : ' <span class="mute">' . esc_html__( '(not measured yet)', 'zorderz' ) . '</span>' ) . '</td></tr>';
		}
		$h .= '</tbody></table></div>';
		if ( $poe ) {
			$h .= '<p class="note">' . esc_html( sprintf( __( '%s: pointed at Poe by an admin. These calls send their (coded) text and any pictures to Poe, which has no zero-retention contract.', 'zorderz' ), implode( ', ', $poe ) ) ) . '</p>';
		}
		$entries = ZDZ_Core_LLM::entries();
		$h      .= '<h2>' . esc_html__( 'OpenAI-compatible entries', 'zorderz' ) . '</h2>';
		if ( ! $entries ) {
			$h .= '<p class="mute">' . esc_html__( 'None. An entry is a base address, a model and the name of its key in the credentials; one without checked data terms cannot carry business data.', 'zorderz' ) . '</p>';
		} else {
			$h .= '<div class="tscroll"><table><thead><tr><th>' . esc_html__( 'Entry', 'zorderz' ) . '</th><th>' . esc_html__( 'Address', 'zorderz' ) . '</th><th>' . esc_html__( 'Model', 'zorderz' ) . '</th><th>' . esc_html__( 'Data terms', 'zorderz' ) . '</th></tr></thead><tbody>';
			foreach ( $entries as $id => $e ) {
				$checked = '' !== trim( (string) ( $e['terms'] ?? '' ) ) && ! empty( $e['terms_checked_by'] ) && ! empty( $e['terms_checked_at'] );
				$h      .= '<tr><td>' . esc_html( $id ) . '</td><td>' . esc_html( (string) ( $e['base'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $e['model'] ?? '' ) ) . '</td><td>' . ( $checked ? '<span class="pill good">' . esc_html__( 'checked', 'zorderz' ) . '</span> ' . esc_html( (string) $e['terms'] ) : '<span class="pill warn">' . esc_html__( 'not checked: no business data', 'zorderz' ) . '</span>' ) . '</td></tr>';
			}
			$h .= '</tbody></table></div>';
		}
		return $h;
	}

	/* ================================================================== Setup (what a connections pack would set) */

	/** The slot option a model is stored in (the Model Registry's own option). */
	private static function slot_option( $key ) {
		$prefix = class_exists( 'ZDZ_Model_Registry' ) ? ZDZ_Model_Registry::OPT_PREFIX : 'zdz_model_slot_';
		return $prefix . ZDZ_Core_LLM::SLOTS[ $key ];
	}

	/** A model id as an admin may type it: letters, digits and . _ : / -, at most 100 characters. */
	public static function clean_model( $m ) {
		$m = trim( (string) $m );
		return ( '' !== $m && strlen( $m ) <= 100 && preg_match( '#^[A-Za-z0-9._:/\-]+$#', $m ) ) ? $m : '';
	}

	/** A non-negative amount below $max, or null. */
	public static function clean_amount( $v, $max ) {
		if ( ! is_scalar( $v ) || '' === trim( (string) $v ) || ! is_numeric( trim( (string) $v ) ) ) {
			return null;
		}
		$f = (float) trim( (string) $v );
		return ( $f >= 0 && $f <= $max ) ? $f : null;
	}

	public static function setup_html( $form ) {
		$labels = array( 'primary' => __( 'Primary model (every kind of call)', 'zorderz' ), 'quick' => __( 'Quick model (when time is short)', 'zorderz' ), 'fallback' => __( 'Fallback model (after the primary fails)', 'zorderz' ) );
		$prices = ZDZ_Core_LLM::price_table();
		$conn   = ZDZ_Core_LLM::connection();
		$b      = ZDZ_Core_LLM::budgets();
		$h      = '<h2>' . esc_html__( 'Setup', 'zorderz' ) . '</h2><p class="mute">' . esc_html__( 'Model names exactly as the provider spells them. Prices are USD per million tokens, from the provider\'s price list. A model with no prices is never called, and nothing is sent until a monthly budget is set.', 'zorderz' ) . '</p>';
		// The key and its workspace live in the credentials layer; only whether each is there is shown, never a value.
		$has_key = class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_credential' ) && '' !== (string) ZDZ_Core_Settings::get_credential( 'anthropic_api_key' );
		$has_ws  = '' !== ZDZ_LLM_Anthropic::workspace();
		$h      .= '<p>' . esc_html__( 'Anthropic key:', 'zorderz' ) . ' ' . ( $has_key ? '<span class="pill good">' . esc_html__( 'stored', 'zorderz' ) . '</span>' : '<span class="pill bad">' . esc_html__( 'not stored', 'zorderz' ) . '</span>' )
			. ' · ' . esc_html__( 'Its workspace ID:', 'zorderz' ) . ' ' . ( $has_ws ? '<span class="pill good">' . esc_html__( 'set', 'zorderz' ) . '</span>' : '<span class="pill bad">' . esc_html__( 'not set: nothing is sent', 'zorderz' ) . '</span>' )
			. ' <span class="mute">' . esc_html__( '(both are entered in the Zorderz credentials)', 'zorderz' ) . '</span></p>';
		$h      .= $form;
		foreach ( $labels as $key => $label ) {
			$opt = self::slot_option( $key );
			$val = function_exists( 'get_option' ) ? (string) get_option( $opt, '' ) : '';
			$h  .= '<label for="zdz-llm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input type="text" id="zdz-llm-' . esc_attr( $key ) . '" name="models[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" autocomplete="off" spellcheck="false">';
		}
		$cols = array( __( 'In', 'zorderz' ), __( 'Cache write 5 min', 'zorderz' ), __( 'Cache write 1 h', 'zorderz' ), __( 'Cache read', 'zorderz' ), __( 'Out', 'zorderz' ) );
		$h   .= '<h2>' . esc_html__( 'Prices', 'zorderz' ) . '</h2>';
		$names = ZDZ_Core_LLM::approved_models();
		if ( ! $names ) {
			$h .= '<p class="mute">' . esc_html__( 'Save the models first; each then gets a row of prices here.', 'zorderz' ) . '</p>';
		} else {
			$h .= '<div class="tscroll"><table><thead><tr><th>' . esc_html__( 'Model', 'zorderz' ) . '</th>';
			foreach ( $cols as $c ) {
				$h .= '<th class="n">' . esc_html( $c ) . '</th>';
			}
			$h .= '</tr></thead><tbody>';
			foreach ( $names as $i => $model ) {
				$p  = $prices[ $model ] ?? array( '', '', '', '', '' );
				$h .= '<tr><td>' . esc_html( $model ) . '<input type="hidden" name="prices[' . (int) $i . '][model]" value="' . esc_attr( $model ) . '"></td>';
				foreach ( $cols as $j => $c ) {
					$h .= '<td class="n"><input type="number" step="0.0001" min="0" aria-label="' . esc_attr( $model . ' ' . $c ) . '" name="prices[' . (int) $i . '][p][' . (int) $j . ']" value="' . esc_attr( '' === $p[ $j ] ? '' : (string) $p[ $j ] ) . '"></td>';
				}
				$h .= '</tr>';
			}
			$h .= '</tbody></table></div>';
		}
		$h .= '<h2>' . esc_html__( 'Region', 'zorderz' ) . '</h2>';
		$h .= '<label for="zdz-llm-geo">' . esc_html__( 'Where the provider runs each call (empty: the provider\'s default)', 'zorderz' ) . '</label><input type="text" id="zdz-llm-geo" name="geo" value="' . esc_attr( $conn['inference_geo'] ) . '" autocomplete="off" spellcheck="false">';
		$h .= '<label for="zdz-llm-geo-f">' . esc_html__( 'What that region costs over the list price (1 = the same; 1.1 = ten per cent more)', 'zorderz' ) . '</label><input type="number" step="0.01" min="1" max="3" id="zdz-llm-geo-f" name="geo_factor" value="' . esc_attr( (string) $conn['geo_price_factor'] ) . '">';
		$h .= '<h2>' . esc_html__( 'Monthly budget (USD)', 'zorderz' ) . '</h2>';
		$h .= '<label for="zdz-llm-b-app">' . esc_html__( 'This site\'s apps may spend', 'zorderz' ) . '</label><input type="number" step="0.01" min="0" id="zdz-llm-b-app" name="budget[app]" value="' . esc_attr( null === $b['app'] ? '' : (string) $b['app'] ) . '">';
		$h .= '<label for="zdz-llm-b-all">' . esc_html__( 'Everything on the Ai account stops at (empty: the same as the site\'s apps)', 'zorderz' ) . '</label><input type="number" step="0.01" min="0" id="zdz-llm-b-all" name="budget[all]" value="' . esc_attr( null === $b['all'] ? '' : (string) $b['all'] ) . '">';
		$h .= '<label for="zdz-llm-b-ceil">' . esc_html__( 'The account\'s own hard limit at the provider, if one is set (shown only)', 'zorderz' ) . '</label><input type="number" step="0.01" min="0" id="zdz-llm-b-ceil" name="budget[ceiling]" value="' . esc_attr( null === $b['ceiling'] ? '' : (string) $b['ceiling'] ) . '">';
		$h .= '<p><button class="btn" type="submit">' . esc_html__( 'Save setup', 'zorderz' ) . '</button></p></form>';
		return $h;
	}

	/**
	 * The setup form's values, checked: models, prices, region, budget. Pure (the suites call it); unreadable values are
	 * dropped, never guessed.
	 *
	 * @return array{models:array,prices:array,connection:array,budgets:array}
	 */
	public static function clean_setup( array $post ) {
		$models = array();
		foreach ( array_keys( ZDZ_Core_LLM::SLOTS ) as $k ) {
			$models[ $k ] = self::clean_model( $post['models'][ $k ] ?? '' );
		}
		$prices = array();
		foreach ( (array) ( $post['prices'] ?? array() ) as $row ) {
			$m = self::clean_model( is_array( $row ) ? ( $row['model'] ?? '' ) : '' );
			$p = is_array( $row ) ? (array) ( $row['p'] ?? array() ) : array();
			if ( '' === $m || ! in_array( $m, $models, true ) ) {
				continue;
			}
			$vals = array();
			for ( $j = 0; $j < 5; $j++ ) {
				$vals[] = self::clean_amount( $p[ $j ] ?? null, self::MAX_PRICE );
			}
			if ( ! in_array( null, $vals, true ) ) {
				$prices[ $m ] = $vals;
			}
		}
		$geo = strtolower( trim( (string) ( $post['geo'] ?? '' ) ) );
		$geo = preg_match( '/^[a-z]{2,12}$/', $geo ) ? $geo : '';
		$f   = self::clean_amount( $post['geo_factor'] ?? null, 3.0 );
		$conn = array( 'inference_geo' => $geo, 'geo_price_factor' => ( null === $f || $f < 1 ) ? 1.0 : $f );
		$budget = array();
		foreach ( array( 'app', 'all', 'ceiling' ) as $k ) {
			$v = self::clean_amount( $post['budget'][ $k ] ?? null, ZDZ_Core_LLM::MAX_BUDGET_USD );
			if ( null !== $v ) {
				$budget[ $k ] = round( $v, 2 );
			}
		}
		if ( ! isset( $budget['app'] ) ) {
			$budget = array();   // no site line, no budget: nothing is sent
		}
		return array( 'models' => $models, 'prices' => $prices, 'connection' => $conn, 'budgets' => $budget );
	}

	/** POST admin-post.php?action=zdz_llm_setup -> the options, then back to the page. */
	public static function handle_setup() {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		check_admin_referer( 'zdz_llm_setup' );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is checked by clean_setup()
		$c    = self::clean_setup( is_array( $post ) ? $post : array() );
		foreach ( $c['models'] as $k => $m ) {
			if ( '' === $m ) {
				delete_option( self::slot_option( $k ) );
			} else {
				update_option( self::slot_option( $k ), $m, false );
			}
		}
		// prices for models no longer in a slot are kept: a model can come back without its prices being typed again
		$keep = get_option( ZDZ_Core_LLM::PRICES_OPTION, array() );
		$keep = is_array( $keep ) ? $keep : array();
		update_option( ZDZ_Core_LLM::PRICES_OPTION, array_merge( $keep, $c['prices'] ), false );
		update_option( ZDZ_Core_LLM::CONN_OPTION, $c['connection'], false );
		if ( $c['budgets'] ) {
			update_option( ZDZ_Core_LLM::BUDGET_OPTION, $c['budgets'], false );
		} else {
			delete_option( ZDZ_Core_LLM::BUDGET_OPTION );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::USAGE_PAGE, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ================================================================== Ai checks */

	/** The golden sets: data/llm-golden/<role>.json, each { role, cases: [ { id, system, messages, json_schema?, max_output, expect } ] }. */
	public static function golden( $dir = null ) {
		$dir = $dir ? $dir : dirname( __DIR__ ) . '/data/llm-golden';
		$out = array();
		foreach ( array_keys( ZDZ_Core_LLM::ROLES ) as $role ) {
			$f = $dir . '/' . $role . '.json';
			if ( ! is_readable( $f ) ) {
				continue;
			}
			$j = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( (array) ( $j['cases'] ?? array() ) as $c ) {
				if ( is_array( $c ) && ! empty( $c['id'] ) ) {
					$c['role']       = $role;
					$out[ $c['id'] ] = $c;
				}
			}
		}
		return $out;
	}

	/** A golden case as a router request (media files read from data/llm-golden/media/). */
	public static function request_for( array $c, $dir = null ) {
		$dir  = $dir ? $dir : dirname( __DIR__ ) . '/data/llm-golden';
		$msgs = array();
		foreach ( (array) $c['messages'] as $m ) {
			$parts = array();
			foreach ( is_string( $m['content'] ) ? array( array( 'type' => 'text', 'text' => $m['content'] ) ) : (array) $m['content'] as $p ) {
				if ( isset( $p['file'] ) ) {
					$f   = $dir . '/media/' . basename( (string) $p['file'] );
					$raw = is_readable( $f ) ? (string) file_get_contents( $f ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					if ( 'pdf' === $p['type'] ) {
						$parts[] = array( 'type' => 'pdf', 'data' => base64_encode( $raw ), 'text' => (string) ( $p['text'] ?? '' ) );
					} else {
						$parts[] = array( 'type' => 'image', 'media_type' => (string) ( $p['media_type'] ?? 'image/png' ), 'data' => base64_encode( $raw ) );
					}
				} else {
					$parts[] = $p;
				}
			}
			$msgs[] = array( 'role' => $m['role'] ?? 'user', 'content' => $parts );
		}
		$req = array( 'system' => (string) ( $c['system'] ?? '' ), 'messages' => $msgs, 'max_output' => (int) ( $c['max_output'] ?? 1500 ) );
		if ( ! empty( $c['json_schema'] ) ) {
			$req['json_schema'] = $c['json_schema'];
		}
		if ( true === ( $c['system_company'] ?? null ) ) {
			$req['system_company'] = true;   // a catalog's words, coded by the business-text rule
		}
		return $req;
	}

	/** The most one case can cost (its worst case on the primary model, as the router prices it). 0 with no prices. */
	public static function worst_usd( array $c, $dir = null ) {
		$rates = ZDZ_Core_LLM::prices_for( ZDZ_Core_LLM::models()['primary'] );
		if ( null === $rates ) {
			return 0.0;
		}
		$req = self::request_for( $c, $dir );
		$n   = array( 'system' => $req['system'], 'messages' => array(), 'json_schema' => $req['json_schema'] ?? null, 'web' => 'web' === $c['role'], 'max_output' => $req['max_output'] );
		foreach ( $req['messages'] as $m ) {
			$n['messages'][] = array( 'role' => $m['role'], 'content' => $m['content'] );
		}
		$fit = ZDZ_Core_LLM::fit( $n, $rates, 1000.0 );
		return $fit ? $fit[1] : 0.0;
	}

	/** The checks' coder (to read the answer back), or null when there is none. */
	private static function coder() {
		if ( class_exists( 'ZDZ_Private' ) && method_exists( 'ZDZ_Private', 'for' ) ) {
			$c = ZDZ_Private::for( ZDZ_Core_LLM::CODER );
			return is_object( $c ) && method_exists( $c, 'decode' ) ? $c : null;
		}
		return null;
	}

	/** Score one answer against a case's expect block. -> [ pass bool, why string ] */
	public static function score( array $c, $answer, $coder = null ) {
		if ( is_wp_error( $answer ) ) {
			return array( false, $answer->get_error_message() );
		}
		$coder = $coder ? $coder : self::coder();
		$text  = $coder ? (string) $coder->decode( (string) $answer['text'], true ) : (string) $answer['text'];
		$json  = is_array( $answer['json'] ) ? self::decode_all( $answer['json'], $coder ) : null;
		$e     = (array) ( $c['expect'] ?? array() );
		foreach ( (array) ( $e['json'] ?? array() ) as $k => $want ) {
			$got = is_array( $json ) ? self::path( $json, (string) $k ) : null;
			if ( is_numeric( $want ) ) {
				if ( ! is_numeric( $got ) || abs( (float) $got - (float) $want ) > 0.011 ) {
					return array( false, $k . ' was ' . wp_json_encode( $got ) );
				}
			} elseif ( is_bool( $want ) ) {
				if ( $got !== $want ) {
					return array( false, $k . ' was ' . wp_json_encode( $got ) );
				}
			} elseif ( 0 !== strcasecmp( trim( (string) $got ), trim( (string) $want ) ) ) {
				return array( false, $k . ' was ' . wp_json_encode( $got ) );
			}
		}
		foreach ( (array) ( $e['contains'] ?? array() ) as $w ) {
			if ( false === stripos( $text, (string) $w ) ) {
				return array( false, 'no "' . $w . '"' );
			}
		}
		foreach ( (array) ( $e['not_contains'] ?? array() ) as $w ) {
			if ( false !== stripos( $text, (string) $w ) ) {
				return array( false, 'said "' . $w . '"' );
			}
		}
		if ( isset( $e['max_words'] ) && str_word_count( $text ) > (int) $e['max_words'] ) {
			return array( false, 'too long' );
		}
		if ( ! empty( $e['citations'] ) && empty( $answer['citations'] ) ) {
			return array( false, 'no sources' );
		}
		return array( true, '' );
	}

	private static function path( array $j, $path ) {
		$v = $j;
		foreach ( explode( '.', $path ) as $k ) {
			if ( is_array( $v ) && array_key_exists( $k, $v ) ) {
				$v = $v[ $k ];
			} elseif ( is_array( $v ) && ctype_digit( $k ) && array_key_exists( (int) $k, $v ) ) {
				$v = $v[ (int) $k ];
			} else {
				return null;
			}
		}
		return $v;
	}

	private static function decode_all( $v, $coder ) {
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::decode_all( $x, $coder );
			}
			return $v;
		}
		return ( is_string( $v ) && $coder ) ? $coder->decode( $v, true ) : $v;
	}

	private static function spend_key() {
		return 'zdz_llm_checks_spend_' . (int) get_current_user_id();
	}

	private static function run_key() {
		return 'zdz_llm_checks_run_' . (int) get_current_user_id();
	}

	/** POST admin-ajax.php?action=zdz_llm_check { case, run, _wpnonce } -> one case through the real router. */
	public static function ajax_check() {
		if ( ! self::allowed() || ! check_ajax_referer( 'zdz_llm_check', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'says' => __( 'Not allowed.', 'zorderz' ) ), 403 );
		}
		$id    = sanitize_key( (string) wp_unslash( $_POST['case'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
		$run   = sanitize_key( (string) wp_unslash( $_POST['run'] ?? '' ) );  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
		$cases = self::golden();
		if ( ! isset( $cases[ $id ] ) || '' === $run ) {
			wp_send_json_error( array( 'says' => __( 'Unknown check.', 'zorderz' ) ), 400 );
		}
		$st = get_transient( self::run_key() );
		$st = is_array( $st ) && ( $st['run'] ?? '' ) === $run ? $st : array( 'run' => $run, 'usd' => 0.0, 'results' => array() );
		// the cap is the person's, an hour at a time, kept on the server: a new run id does not reset it
		$hour = get_transient( self::spend_key() );
		$hour = is_array( $hour ) ? $hour : array( 'usd' => 0.0 );
		if ( $st['usd'] >= self::RUN_CAP || $hour['usd'] >= self::RUN_CAP ) {
			wp_send_json_error( array( 'says' => sprintf( __( 'This run reached its %s limit, so it stopped.', 'zorderz' ), self::money( self::RUN_CAP ) ) ), 200 );
		}
		$c   = $cases[ $id ];
		$t0  = microtime( true );
		$cap = min( 1.0, self::RUN_CAP - max( $st['usd'], $hour['usd'] ) );
		$ans = ZDZ_Core_LLM::ask( $c['role'], self::request_for( $c ), array( 'caller' => self::CHECK_CALLER, 'user_id' => 0, 'deadline' => time() + 85, 'cap_usd' => $cap ) );
		$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		list( $pass, $why ) = self::score( $c, $ans );
		// a failed answer can cost money too (its error carries usd_milli)
		$usd = is_wp_error( $ans ) ? ( (float) ( ( (array) $ans->get_error_data() )['usd_milli'] ?? 0 ) ) / 1000.0 : (float) $ans['usd'];
		$res = array( 'id' => $id, 'role' => $c['role'], 'pass' => $pass, 'why' => $why, 'ms' => $ms, 'usd' => $usd,
			'model' => is_wp_error( $ans ) ? '' : ZDZ_Core_LLM::model_code( $ans['model'] ), 'fallback' => ! is_wp_error( $ans ) && $ans['fallback'] );
		$st['usd']           += $usd;
		$st['results'][ $id ] = $res;
		set_transient( self::run_key(), $st, 3 * 3600 );
		if ( ! isset( $hour['until'] ) ) {
			$hour['until'] = time() + 3600;
		}
		$hour['usd'] += $usd;
		set_transient( self::spend_key(), $hour, max( 60, (int) $hour['until'] - time() ) );
		wp_send_json_success( $res + array( 'run_usd' => round( $st['usd'], 4 ) ) );
	}

	/** Each kind's score, time and cost from a run's results; and the quick-model line the primary's times suggest. */
	public static function summarize( array $results, $primary_code = null ) {
		$primary_code = null === $primary_code ? ZDZ_Core_LLM::model_code( ZDZ_Core_LLM::models()['primary'] ) : (string) $primary_code;
		$by = array();
		foreach ( $results as $r ) {
			$k = $r['role'];
			if ( ! isset( $by[ $k ] ) ) {
				$by[ $k ] = array( 'n' => 0, 'pass' => 0, 'ms' => array(), 'usd' => 0.0, 'primary_ms' => array() );
			}
			$by[ $k ]['n']++;
			$by[ $k ]['pass'] += $r['pass'] ? 1 : 0;
			$by[ $k ]['ms'][]  = (int) $r['ms'];
			$by[ $k ]['usd']  += (float) $r['usd'];
			if ( '' !== $primary_code && $primary_code === $r['model'] ) {
				$by[ $k ]['primary_ms'][] = (int) $r['ms'];
			}
		}
		foreach ( $by as $k => $v ) {
			sort( $v['ms'] );
			$by[ $k ]['median_ms'] = $v['ms'] ? $v['ms'][ (int) floor( ( count( $v['ms'] ) - 1 ) / 2 ) ] : 0;
			$by[ $k ]['max_ms']    = $v['ms'] ? max( $v['ms'] ) : 0;
			// the primary needs up to its slowest time here; a quarter more, and 5 s for the page around it, rounded up
			$by[ $k ]['line_s']    = $v['primary_ms'] ? (int) min( 90, max( 15, ceil( max( $v['primary_ms'] ) / 1000 * 1.25 + 5 ) ) ) : null;
		}
		return $by;
	}

	/** POST admin-ajax.php?action=zdz_llm_check_done { run } -> the run's summary, kept as the last run. */
	public static function ajax_done() {
		if ( ! self::allowed() || ! check_ajax_referer( 'zdz_llm_check', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'says' => __( 'Not allowed.', 'zorderz' ) ), 403 );
		}
		$run = sanitize_key( (string) wp_unslash( $_POST['run'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
		$st  = get_transient( self::run_key() );
		if ( ! is_array( $st ) || ( $st['run'] ?? '' ) !== $run ) {
			wp_send_json_error( array( 'says' => __( 'That run is not here any more.', 'zorderz' ) ), 400 );
		}
		$last = array( 'at' => time(), 'usd' => round( $st['usd'], 4 ), 'results' => array_values( $st['results'] ), 'roles' => self::summarize( $st['results'] ) );
		update_option( self::LAST_OPTION, $last, false );
		wp_send_json_success( array( 'html' => self::results_html( $last ) ) );
	}

	/** "Use these time lines": the measured lines become each kind's quick-model line. */
	public static function handle_lines() {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		check_admin_referer( 'zdz_llm_lines' );
		$last  = get_option( self::LAST_OPTION, array() );
		$lines = array();
		foreach ( (array) ( $last['roles'] ?? array() ) as $role => $v ) {
			if ( isset( ZDZ_Core_LLM::ROLES[ $role ] ) && ! empty( $v['line_s'] ) ) {
				$lines[ $role ] = (int) $v['line_s'];
			}
		}
		update_option( ZDZ_Core_LLM::LINES_OPTION, $lines, false );
		wp_safe_redirect( add_query_arg( array( 'page' => self::CHECKS_PAGE, 'lines' => count( $lines ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function results_html( array $last ) {
		if ( empty( $last['roles'] ) ) {
			return '<p class="mute">' . esc_html__( 'No run yet.', 'zorderz' ) . '</p>';
		}
		$h  = '<p class="mute">' . esc_html( sprintf( __( 'Last run: %1$s UTC, cost %2$s.', 'zorderz' ), gmdate( 'j M Y, H:i', (int) $last['at'] ), self::money( $last['usd'] ) ) ) . '</p>';
		$h .= '<table><thead><tr><th>' . esc_html__( 'Kind', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Score', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Median time', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Slowest', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Cost', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Quick model under', 'zorderz' ) . '</th></tr></thead><tbody>';
		foreach ( $last['roles'] as $role => $v ) {
			$cls = $v['pass'] === $v['n'] ? 'good' : ( $v['pass'] >= $v['n'] - 1 ? 'warn' : 'bad' );
			$h  .= '<tr><td>' . esc_html( $role ) . '</td><td class="n"><span class="pill ' . esc_attr( $cls ) . '">' . (int) $v['pass'] . ' / ' . (int) $v['n'] . '</span></td><td class="n">' . esc_html( number_format( $v['median_ms'] / 1000, 1 ) ) . ' s</td><td class="n">' . esc_html( number_format( $v['max_ms'] / 1000, 1 ) ) . ' s</td><td class="n">' . esc_html( self::money( $v['usd'] ) ) . '</td><td class="n">' . ( null === $v['line_s'] ? '-' : esc_html( (string) $v['line_s'] ) . ' s' ) . '</td></tr>';
		}
		$h   .= '</tbody></table>';
		$miss = array_filter( (array) $last['results'], function ( $r ) {
			return empty( $r['pass'] );
		} );
		if ( $miss ) {
			$h .= '<h2>' . esc_html__( 'What missed', 'zorderz' ) . '</h2><table><thead><tr><th>' . esc_html__( 'Check', 'zorderz' ) . '</th><th>' . esc_html__( 'Why', 'zorderz' ) . '</th><th>' . esc_html__( 'Model', 'zorderz' ) . '</th></tr></thead><tbody>';
			foreach ( $miss as $r ) {
				$h .= '<tr><td>' . esc_html( $r['id'] ) . '</td><td>' . esc_html( (string) $r['why'] ) . '</td><td>' . esc_html( (string) $r['model'] ) . '</td></tr>';
			}
			$h .= '</tbody></table>';
		}
		return $h;
	}

	public static function render_checks() {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		$has_key = class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_credential' ) && '' !== (string) ZDZ_Core_Settings::get_credential( 'anthropic_api_key' );
		$form    = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'zdz_llm_lines', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="zdz_llm_lines">';
		$lines   = isset( $_GET['lines'] ) ? (int) $_GET['lines'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display flag only
		echo self::checks_html( self::golden(), get_option( self::LAST_OPTION, array() ), $has_key, wp_create_nonce( 'zdz_llm_check' ), admin_url( 'admin-ajax.php' ), $form, $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside
	}

	/** The page body (pure, for the suites). */
	public static function checks_html( array $cases, $last, $has_key, $nonce, $ajax, $lines_form, $lines_saved = null ) {
		$worst = 0.0;
		$roles = array();
		foreach ( $cases as $c ) {
			$worst += self::worst_usd( $c );
			$roles[ $c['role'] ] = ( $roles[ $c['role'] ] ?? 0 ) + 1;
		}
		$priced = null !== ZDZ_Core_LLM::prices_for( ZDZ_Core_LLM::models()['primary'] );
		$h  = self::styles() . '<div class="wrap"><div class="zdz-llm" data-zdz-llm="checks">';
		$h .= '<h1>' . esc_html__( 'Ai checks', 'zorderz' ) . '</h1><p class="mute">' . esc_html( sprintf( __( 'Runs %1$d made-up checks (%2$s) through the real router: made-up names, a drawn invoice, a drawn timesheet, no customer data.', 'zorderz' ), count( $cases ), implode( ', ', array_map( function ( $r, $n ) {
			return $r . ' ' . $n;
		}, array_keys( $roles ), $roles ) ) ) ) . '</p>';
		if ( null !== $lines_saved ) {
			$h .= '<p class="note">' . esc_html( sprintf( __( 'Saved the quick-model time line for %d kinds of call.', 'zorderz' ), (int) $lines_saved ) ) . '</p>';
		}
		$h .= '<div class="tiles"><div class="tile"><span>' . esc_html__( 'This run costs at most', 'zorderz' ) . '</span><b>' . esc_html( $priced ? self::money( min( $worst, self::RUN_CAP ) ) : self::money( self::RUN_CAP ) ) . '</b><span>' . esc_html( sprintf( __( 'each check priced at its worst case; a run stops at %s', 'zorderz' ), self::money( self::RUN_CAP ) ) ) . '</span></div>';
		$h .= '<div class="tile"><span>' . esc_html__( 'Takes about', 'zorderz' ) . '</span><b>' . esc_html( (string) max( 1, (int) ceil( count( $cases ) * 12 / 60 ) ) ) . ' min</b><span>' . esc_html__( 'one check at a time; keep this page open', 'zorderz' ) . '</span></div></div>';
		if ( ! $has_key ) {
			$h .= '<p class="note">' . esc_html__( 'No Anthropic key is stored yet (Zorderz credentials), so the checks cannot run.', 'zorderz' ) . '</p>';
		}
		$h .= '<p><button type="button" class="btn" id="zdz-llm-run"' . ( $has_key && $cases ? '' : ' disabled' ) . '>' . esc_html__( 'Run the Ai checks', 'zorderz' ) . '</button></p>';
		$h .= '<div class="status" id="zdz-llm-status" role="status" aria-live="polite"></div>';
		$h .= '<div id="zdz-llm-results">' . self::results_html( is_array( $last ) ? $last : array() ) . '</div>';
		if ( is_array( $last ) && ! empty( $last['roles'] ) ) {
			$h .= $lines_form . '<p class="mute">' . esc_html__( 'The quick-model line is the time left under which a call uses the quick model instead of the primary. Every line is 40 s until a run measures it.', 'zorderz' ) . '</p><p><button class="btn ghost" type="submit">' . esc_html__( 'Use these time lines', 'zorderz' ) . '</button></p></form>';
		}
		$ids  = array_keys( $cases );
		$says = array(
			'done'     => __( 'Done: %1$s of %2$s passed, %3$s.', 'zorderz' ),
			'saving'   => __( 'Saving...', 'zorderz' ),
			'reload'   => __( 'Reload the page to use the time lines.', 'zorderz' ),
			'unsaved'  => __( 'The results could not be saved.', 'zorderz' ),
			'retry'    => __( 'The results could not be saved. Try again.', 'zorderz' ),
			'check'    => __( 'Check %1$s of %2$s: %3$s...', 'zorderz' ),
			'norun'    => __( 'That check could not run.', 'zorderz' ),
			'noanswer' => __( 'Check %s did not answer; going on.', 'zorderz' ),
		);
		$h .= '<script>(function(){var ids=' . wp_json_encode( $ids ) . ',ajax=' . wp_json_encode( $ajax ) . ',nonce=' . wp_json_encode( $nonce ) . ',S=' . wp_json_encode( $says ) . ';'
			. 'function f(t,a){return t.replace(/%(\\d)\\$s|%s/g,function(m,i){return String(a[i?i-1:0]);});}'
			. 'var b=document.getElementById("zdz-llm-run"),s=document.getElementById("zdz-llm-status"),out=document.getElementById("zdz-llm-results");if(!b)return;'
			. 'function post(a,fd){var d=new FormData();d.append("action",a);d.append("_wpnonce",nonce);for(var k in fd)d.append(k,fd[k]);return fetch(ajax,{method:"POST",body:d,credentials:"same-origin"}).then(function(r){return r.json();});}'
			. 'b.addEventListener("click",function(){b.disabled=true;var run=String(Date.now()),i=0,passed=0,usd=0;'
			. 'function done(){return f(S.done,[passed,ids.length,"$"+usd.toFixed(2)]);}'
			. 'function next(){if(i>=ids.length){s.textContent=done()+" "+S.saving;post("zdz_llm_check_done",{run:run}).then(function(r){if(r&&r.success){out.innerHTML=r.data.html;s.textContent=done()+" "+S.reload;}else{s.textContent=(r&&r.data&&r.data.says)||S.unsaved;}b.disabled=false;}).catch(function(){s.textContent=S.retry;b.disabled=false;});return;}'
			. 'var id=ids[i++];s.textContent=f(S.check,[i,ids.length,id]);'
			. 'post("zdz_llm_check",{run:run,"case":id}).then(function(r){if(r&&r.success){if(r.data.pass)passed++;usd=r.data.run_usd||usd;next();}else{s.textContent=(r&&r.data&&r.data.says)||S.norun;i=ids.length;next();}}).catch(function(){s.textContent=f(S.noanswer,[id]);next();});}'
			. 'next();});})();</script>';
		$h .= '</div></div>';
		return $h;
	}
}
