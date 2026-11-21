<?php
/**
 * ZANA_Research_Settings — deep research: whether it is on, and its limits.
 *
 * Deep research is OFF until an administrator switches it on (Zorderz → Deep research), and even then it runs only
 * when the site's Ai is set up: a monthly Ai budget, an Ai model, and the workspace the Ai key must come from. With
 * any of those missing no run starts, and the page says which.
 *
 * One option, `zana_research`, checked here on the way in and again on the way out, so a stored value outside its
 * limits is read as the nearest value inside them. Core's own numbers are modest on purpose:
 *
 *   round_minutes    5     one round's time; a run that stops on time offers "Keep going" (1 to 30)
 *   round_usd        1     one round's cost, in US dollars (0.25 to 10)
 *   run_minutes     20     a whole run, its "Keep going" rounds included (5 to 120)
 *   run_usd          5     a whole run's cost (0.5 to 50)
 *   max_steps       24     research steps in a whole run (3 to 80)
 *   day_calls       60     Ai calls a day for research, the whole site (20 to 400: a run needs 20 to finish; no "unlimited")
 *   person_runs_day  3     runs one person may start a day (1 to 20)
 *   person_usd_day   5     what one person's research may cost a day (0.5 to 50)
 *   vetting         on     outside facts are checked against independent sites before they are written up
 *   min_sources      2     independent sites that must agree (2 to 5; never fewer than 2)
 *   max_claims       6     outside facts checked in one run (1 to 12)
 *   owner_groups   none    domains with one owner, one group a line (a publisher's network)
 *   deny_hosts     none    sites the page check never reads           (kept in the page reader's own option,
 *   allow_hosts    none    when set, the only sites it reads           zdz_page_fetch_hosts)
 *
 * WHO MAY START A RUN is not a number here: an administrator-level account that holds "Deep research queries" on
 * Zorderz → Data Permissions (per role, and per person). Web steps also need "Web research queries".
 *
 * An Identity Pack may write the limits (rules.yml → research), never the switch: turning on a feature that spends
 * money and reads the web is a person's choice on this site.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Research_Settings {

	const OPTION = 'zana_research';
	const PAGE   = 'zana-research';
	const GROUP  = 'zana_research_group';

	const DEFAULTS = array(
		'enabled'         => false,
		'round_minutes'   => 5,
		'round_usd'       => 1.0,
		'run_minutes'     => 20,
		'run_usd'         => 5.0,
		'max_steps'       => 24,
		'day_calls'       => 60,
		'person_runs_day' => 3,
		'person_usd_day'  => 5.0,
		'vetting'         => true,
		'min_sources'     => 2,
		'max_claims'      => 6,
		'owner_groups'    => '',
	);

	/** key => [ lowest, highest ] for the numbers. */
	const LIMITS = array(
		'round_minutes'   => array( 1, 30 ),
		'round_usd'       => array( 0.25, 10 ),
		'run_minutes'     => array( 5, 120 ),
		'run_usd'         => array( 0.5, 50 ),
		'max_steps'       => array( 3, 80 ),
		'day_calls'       => array( 20, 400 ),   // 20: ZANA_Research_Gate::START_MIN_DAY_CALLS, the calls one run needs
		'person_runs_day' => array( 1, 20 ),
		'person_usd_day'  => array( 0.5, 50 ),
		'min_sources'     => array( 2, 5 ),
		'max_claims'      => array( 1, 12 ),
	);

	const MONEY = array( 'round_usd', 'run_usd', 'person_usd_day' );

	/* ================================================================== reading */

	/** Every setting, inside its limits. */
	public static function all(): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return self::clean( is_array( $raw ) ? $raw : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	public static function enabled(): bool {
		return (bool) self::get( 'enabled' );
	}

	/**
	 * A raw array made safe: unknown keys dropped, numbers inside their limits, a round never larger than the whole
	 * run. Anything missing or unreadable is Core's default.
	 */
	public static function clean( array $raw ): array {
		$out = self::DEFAULTS;
		foreach ( array( 'enabled', 'vetting' ) as $k ) {
			if ( array_key_exists( $k, $raw ) ) {
				$out[ $k ] = self::truthy( $raw[ $k ] );
			}
		}
		foreach ( self::LIMITS as $k => $lim ) {
			if ( ! array_key_exists( $k, $raw ) || ! is_numeric( $raw[ $k ] ) ) {
				continue;
			}
			$v         = max( $lim[0], min( $lim[1], (float) $raw[ $k ] ) );
			$out[ $k ] = in_array( $k, self::MONEY, true ) ? round( $v, 2 ) : (int) round( $v );
		}
		$out['round_minutes'] = min( $out['round_minutes'], $out['run_minutes'] );
		$out['round_usd']     = min( $out['round_usd'], $out['run_usd'] );
		if ( isset( $raw['owner_groups'] ) && is_string( $raw['owner_groups'] ) ) {
			$out['owner_groups'] = self::clean_groups( $raw['owner_groups'] );
		}
		return $out;
	}

	private static function truthy( $v ): bool {
		return true === $v || 1 === $v || '1' === $v || 'on' === $v || 'yes' === $v || 'true' === $v;
	}

	/** Owner groups as kept: one group a line, lower-case host names only, at most 40 lines of 20 names. */
	public static function clean_groups( string $raw ): string {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$names = array();
			foreach ( explode( ',', $line ) as $d ) {
				$d = strtolower( trim( $d ) );
				if ( preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d ) && strlen( $d ) <= 120 ) {
					$names[ $d ] = $d;
				}
			}
			if ( count( $names ) >= 2 ) {
				$lines[] = implode( ', ', array_slice( array_values( $names ), 0, 20 ) );
			}
			if ( count( $lines ) >= 40 ) {
				break;
			}
		}
		return implode( "\n", $lines );
	}

	/* ================================================================== is the site's Ai set up? */

	/**
	 * What deep research needs from the site before any run: [] when it is all there, else the missing parts in words.
	 * Checked before a run starts, so nothing is spent finding out; the Ai router refuses each of these itself too.
	 *
	 * @return string[]
	 */
	public static function missing(): array {
		$out = array();
		if ( ! class_exists( 'ZDZ_Core_LLM' ) || ! class_exists( 'ZDZ_Step_Runner' ) || ! class_exists( 'ZDZ_Page_Fetch' ) ) {
			return array( __( 'this version of Zorderz does not have what deep research runs on', 'zorderz' ) );
		}
		$b = ZDZ_Core_LLM::budgets();
		if ( empty( $b['set'] ) ) {
			$out[] = __( 'no monthly Ai budget is set (Zorderz → Ai usage)', 'zorderz' );
		}
		$m = ZDZ_Core_LLM::models();
		if ( '' === (string) ( $m['primary'] ?? '' ) ) {
			$out[] = __( 'no Ai model is chosen (Zorderz → Ai usage)', 'zorderz' );
		}
		if ( ! class_exists( 'ZDZ_LLM_Anthropic' ) || '' === (string) ZDZ_LLM_Anthropic::workspace() ) {
			$out[] = __( 'the workspace the Ai key must come from is not named, so zero data retention cannot be checked (Zorderz → Credentials)', 'zorderz' );
		}
		return $out;
	}

	/* ================================================================== the chat page */

	/**
	 * What the chat page's script is told: whether this person could start a run (the buttons are drawn only then;
	 * the server decides again on every press), one round's size for the buttons' words, and the labels.
	 */
	public static function page_config( int $uid ): array {
		$s   = self::all();
		$can = class_exists( 'ZANA_Research_Gate' ) && '' === ZANA_Research_Gate::site_refusal() && ZANA_Research_Gate::could_ever( $uid );
		return array(
			'canStart' => (bool) $can,
			'roundMin' => (int) $s['round_minutes'],
			'roundUsd' => (float) $s['round_usd'],
			'i18n'     => array(
				'loading'       => __( 'Deep research…', 'zorderz' ),
				'error'         => __( 'Something went wrong. Please try again.', 'zorderz' ),
				'working'       => __( 'Working…', 'zorderz' ),
				'planTitle'     => __( 'Deep research: the plan', 'zorderz' ),
				'planCost'      => __( 'Nothing more is spent until you press Start. One round: up to {min} min and {usd}.', 'zorderz' ),
				'start'         => __( 'Start', 'zorderz' ),
				'startWeb'      => __( 'Start, with the web', 'zorderz' ),
				'startOwn'      => __( 'Start, your own data only', 'zorderz' ),
				'cancel'        => __( 'Cancel', 'zorderz' ),
				'stop'          => __( 'Stop', 'zorderz' ),
				'noWeb'         => __( 'Web research is off for your account, so this run uses the business\'s own data only.', 'zorderz' ),
				'toolWeb'       => __( 'the web', 'zorderz' ),
				'toolRecords'   => __( 'your records', 'zorderz' ),
				'toolData'      => __( 'your data', 'zorderz' ),
				'left'          => __( '{t} left', 'zorderz' ),
				'stepOf'        => __( 'Step {n} of {m}', 'zorderz' ),
				'nSources'      => __( '{n} sources', 'zorderz' ),
				'costOf'        => __( '{a} of {b}', 'zorderz' ),
				'withWeb'       => __( 'with the web', 'zorderz' ),
				'ownOnly'       => __( 'your own data only', 'zorderz' ),
				'showSteps'     => __( 'What it has done ({n})', 'zorderz' ),
				'howResearched' => __( 'How this was researched', 'zorderz' ),
				'keepGoing'     => __( 'Keep going (up to {min} min · {usd})', 'zorderz' ),
				'goDeeper'      => __( 'Go deeper (deep research: up to {min} min · {usd})', 'zorderz' ),
				'spent'         => __( 'This run has used its whole time or cost limit.', 'zorderz' ),
				'webOne'        => __( '{n} web search', 'zorderz' ),
				'webMany'       => __( '{n} web searches', 'zorderz' ),
				'dataOne'       => __( '{n} data answer', 'zorderz' ),
				'dataMany'      => __( '{n} data answers', 'zorderz' ),
				'noteOne'       => __( '{n} finding kept', 'zorderz' ),
				'noteMany'      => __( '{n} findings kept', 'zorderz' ),
				'vetOne'        => __( '{n} fact checked', 'zorderz' ),
				'vetMany'       => __( '{n} facts checked', 'zorderz' ),
				'lostOne'       => __( '{n} step restarted', 'zorderz' ),
				'lostMany'      => __( '{n} steps restarted', 'zorderz' ),
			),
		);
	}

	/* ================================================================== the settings page */

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 46 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'zdz_identity_pack_consumers', array( __CLASS__, 'register_pack' ) );
	}

	public static function menu(): void {
		$parent = menu_page_url( 'zdz-core-settings', false ) ? 'zdz-core-settings' : 'options-general.php';
		add_submenu_page( $parent, __( 'Deep research', 'zorderz' ), __( 'Deep research', 'zorderz' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function register(): void {
		register_setting( self::GROUP, self::OPTION, array( 'type' => 'array', 'default' => array(), 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	/**
	 * The form, on its way to the option. The two site lists are kept in the page reader's own option (it reads them),
	 * not in this one.
	 */
	public static function sanitize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		if ( ( array_key_exists( 'deny_hosts', $raw ) || array_key_exists( 'allow_hosts', $raw ) ) && class_exists( 'ZDZ_Page_Fetch' ) && function_exists( 'update_option' ) ) {
			$lists = ZDZ_Page_Fetch::sanitize_hosts( array(
				'deny'  => preg_split( '/[\s,]+/', is_string( $raw['deny_hosts'] ?? null ) ? $raw['deny_hosts'] : '', -1, PREG_SPLIT_NO_EMPTY ),
				'allow' => preg_split( '/[\s,]+/', is_string( $raw['allow_hosts'] ?? null ) ? $raw['allow_hosts'] : '', -1, PREG_SPLIT_NO_EMPTY ),
			) );
			update_option( ZDZ_Page_Fetch::HOSTS_OPTION, $lists, false );
		}
		return self::clean( $raw );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = self::all();
		$missing = self::missing();
		$lists   = class_exists( 'ZDZ_Page_Fetch' ) ? ZDZ_Page_Fetch::hosts() : array( 'allow' => array(), 'deny' => array() );
		$name    = function ( $k ) {
			return self::OPTION . '[' . $k . ']';
		};
		echo '<div class="wrap"><h1>' . esc_html__( 'Deep research', 'zorderz' ) . '</h1>';
		settings_errors();
		echo '<p>' . esc_html__( 'A long answer the chat works out in steps: a plan you start, questions to your own records and to the web, a check of outside facts against independent sites, then a write-up. It costs more than a normal answer, so it is off until you switch it on, and every run stays inside the limits below.', 'zorderz' ) . '</p>';
		if ( $missing ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Deep research cannot run yet:', 'zorderz' ) . '</strong> ' . esc_html( implode( '; ', $missing ) ) . '.</p></div>';
		}
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation">';
		$row = function ( $label, $html, $desc = '' ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . ( '' !== $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is built below from escaped parts only
		};
		$check = function ( $k ) use ( $s, $name ) {
			// the hidden field first, so an unticked box still posts "0" (a missing key would read as Core's default)
			return '<input type="hidden" name="' . esc_attr( $name( $k ) ) . '" value="0" /><label><input type="checkbox" name="' . esc_attr( $name( $k ) ) . '" value="1"' . checked( ! empty( $s[ $k ] ), true, false ) . ' /> ' . esc_html__( 'On', 'zorderz' ) . '</label>';
		};
		$num = function ( $k ) use ( $s, $name ) {
			$lim  = self::LIMITS[ $k ];
			$step = in_array( $k, self::MONEY, true ) ? '0.25' : '1';
			return '<input type="number" class="small-text" min="' . esc_attr( (string) $lim[0] ) . '" max="' . esc_attr( (string) $lim[1] ) . '" step="' . esc_attr( $step ) . '" name="' . esc_attr( $name( $k ) ) . '" value="' . esc_attr( (string) $s[ $k ] ) . '" />';
		};
		$area = function ( $k, $value ) use ( $name ) {
			return '<textarea rows="3" cols="60" class="code" name="' . esc_attr( $name( $k ) ) . '">' . esc_textarea( $value ) . '</textarea>';
		};
		$row( __( 'Deep research', 'zorderz' ), $check( 'enabled' ), __( 'Off: nobody can start a run and the chat never offers one. Who may start one is set on Zorderz → Data Permissions ("Deep research queries"), for administrator-level accounts only; web steps also need "Web research queries".', 'zorderz' ) );
		$row( __( 'One round: minutes', 'zorderz' ), $num( 'round_minutes' ), __( 'A run works for one round, then writes up what it has. If it stopped on time or cost it offers "Keep going".', 'zorderz' ) );
		$row( __( 'One round: cost (USD)', 'zorderz' ), $num( 'round_usd' ) );
		$row( __( 'A whole run: minutes', 'zorderz' ), $num( 'run_minutes' ), __( 'The most one question may use, its "Keep going" rounds included.', 'zorderz' ) );
		$row( __( 'A whole run: cost (USD)', 'zorderz' ), $num( 'run_usd' ) );
		$row( __( 'A whole run: steps', 'zorderz' ), $num( 'max_steps' ) );
		$row( __( 'Ai calls a day, whole site', 'zorderz' ), $num( 'day_calls' ), __( 'Across every run. A run is not started when too few are left to finish one, and a run in progress writes up early when the day\'s calls are nearly used.', 'zorderz' ) );
		$row( __( 'Runs a day, per person', 'zorderz' ), $num( 'person_runs_day' ) );
		$row( __( 'Cost a day, per person (USD)', 'zorderz' ), $num( 'person_usd_day' ), __( 'The site\'s monthly Ai budget and each person\'s daily chat allowance apply as well, and come first.', 'zorderz' ) );
		$row( __( 'Check outside facts', 'zorderz' ), $check( 'vetting' ), __( 'A fact found on the web is called confirmed only when independent sites agree and the server has read the words on each page. Off: every outside figure is marked unverified.', 'zorderz' ) );
		$row( __( 'Independent sites that must agree', 'zorderz' ), $num( 'min_sources' ) );
		$row( __( 'Outside facts checked in one run', 'zorderz' ), $num( 'max_claims' ), __( 'Each one costs a web search.', 'zorderz' ) );
		$row( __( 'Sites with one owner', 'zorderz' ), $area( 'owner_groups', (string) $s['owner_groups'] ), __( 'One group a line, domains separated by commas (a publisher\'s network). Sites in one group count as one source.', 'zorderz' ) );
		$row( __( 'Sites the page check never reads', 'zorderz' ), $area( 'deny_hosts', implode( "\n", (array) ( $lists['deny'] ?? array() ) ) ), __( 'One host a line; its subdomains are included.', 'zorderz' ) );
		$row( __( 'Read only these sites', 'zorderz' ), $area( 'allow_hosts', implode( "\n", (array) ( $lists['allow'] ?? array() ) ) ), __( 'Leave empty to read any public site. The page check only ever opens public https pages, sends no cookies, and is off on a staging copy.', 'zorderz' ) );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	/* ================================================================== an Identity Pack */

	/** rules.yml → research: the limits (never the switch, never the site lists). */
	public static function register_pack( $consumers ) {
		$consumers = is_array( $consumers ) ? $consumers : array();
		if ( ! class_exists( 'ZDZ_Identity_Pack' ) || ! method_exists( 'ZDZ_Identity_Pack', 'option_consumer' ) ) {
			return $consumers;
		}
		$consumers['assistant-research'] = ZDZ_Identity_Pack::option_consumer( __( 'Deep research', 'zorderz' ), 'rules', array( 'research' ), array( __CLASS__, 'plan_pack' ) );
		return $consumers;
	}

	public static function plan_pack( array $data ): array {
		$plan  = ZDZ_Identity_Pack::plan();
		$path  = 'research';
		$raw   = $data[ $path ] ?? null;
		$known = array_merge( array_keys( self::LIMITS ), array( 'vetting', 'owner_groups' ) );
		ZDZ_Identity_Pack::refuse_extra( $plan, $path, $raw, $known );
		$raw = is_array( $raw ) ? $raw : array();
		foreach ( self::LIMITS as $k => $lim ) {
			if ( ! array_key_exists( $k, $raw ) ) {
				continue;
			}
			$v = $raw[ $k ];
			if ( ! is_numeric( $v ) || (float) $v < $lim[0] || (float) $v > $lim[1] ) {
				$plan['refused'][] = array( 'path' => "$path.$k", 'why' => sprintf( 'A number from %s to %s.', (string) $lim[0], (string) $lim[1] ) );
				continue;
			}
			ZDZ_Identity_Pack::plan_key( $plan, "$path.$k", self::OPTION, $k, in_array( $k, self::MONEY, true ) ? round( (float) $v, 2 ) : (int) round( (float) $v ) );
		}
		if ( array_key_exists( 'vetting', $raw ) ) {
			if ( ! is_bool( $raw['vetting'] ) ) {
				$plan['refused'][] = array( 'path' => "$path.vetting", 'why' => 'true or false.' );
			} else {
				ZDZ_Identity_Pack::plan_key( $plan, "$path.vetting", self::OPTION, 'vetting', $raw['vetting'] );
			}
		}
		if ( array_key_exists( 'owner_groups', $raw ) ) {
			$lines = array();
			$bad   = false;
			foreach ( ZDZ_Identity_Pack::as_list( $raw['owner_groups'] ) as $i => $g ) {
				$one = self::clean_groups( is_array( $g ) ? implode( ', ', array_map( 'strval', $g ) ) : (string) $g );
				if ( '' === $one || false !== strpos( $one, "\n" ) ) {
					$plan['refused'][] = array( 'path' => "$path.owner_groups." . ( $i + 1 ), 'why' => 'Two or more domain names with one owner.' );
					$bad               = true;
					continue;
				}
				$lines[] = $one;
			}
			if ( ! $bad ) {
				ZDZ_Identity_Pack::plan_key( $plan, "$path.owner_groups", self::OPTION, 'owner_groups', implode( "\n", $lines ) );
			}
		}
		if ( $plan['writes'] ) {
			$plan['notes'][] = 'deep research stays off until someone switches it on under Zorderz → Deep research: a pack sets its limits only.';
		}
		return $plan;
	}
}
