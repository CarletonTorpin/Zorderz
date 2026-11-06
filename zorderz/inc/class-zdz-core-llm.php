<?php
/**
 * ZDZ_Core_LLM -- the platform's one Ai router.
 *
 *   ZDZ_Core_LLM::ask( $role, $request, $ctx ) -> array|WP_Error
 *     $role     classify | extract | read_document | write | chat | reason | web
 *     $request  system (string), messages ([ ['role','content' => string | [parts]] ]; parts: text, image, pdf),
 *               json_schema (optional), web (bool), max_output (int); system_company (bool) and a text part's
 *               company (bool) mark words the business wrote (a catalog), coded with ZDZ_Private's company rule;
 *               system_prefix / system_tier (strings) and their *_company marks: the cached blocks (rules 15 and 17);
 *               cache_latest false takes the conversation mark off the last message
 *     $ctx      caller (an app id from CALLERS), user_id (0 for background work), deadline (unix seconds the answer
 *               must arrive by), cap_usd (optional, at most the role's cap), primary_only (bool, rule 14)
 *     returns   text (still CODED: the caller decodes with ZDZ_Private::for( 'llm' )->decode( $text, $show )), json (when
 *               a schema was given and it checked), citations (web), usage, model, provider, fallback, coded_count,
 *               picture_sent, usd, tries, stop
 *
 * WHAT A BUSINESS CONFIGURES (nothing here names a model, a price or a budget):
 *   models     three Model Registry slots: llm_primary (every role), llm_quick (when time is short), llm_fallback
 *              (after the primary fails twice). Options zdz_model_slot_llm_* or the zdz_model_for filter; a connections
 *              pack sets them. A slot left empty is never filled from the chat gateway's base model.
 *   prices     zdz_llm_model_prices (option, filter of the same name): model id => [ in, cache write 5 min, cache
 *              write 1 h, cache read, out ], USD per million tokens. A model with no prices is never called.
 *   region     zdz_llm_connection: inference_geo ('' = the provider's default) and geo_price_factor (what that region
 *              costs over the list price).
 *   budgets    zdz_llm_budgets (option, filter of the same name): the monthly lines in USD -- app (this site's apps),
 *              all (everything on the provider account, defaults to app) and ceiling (the account's own hard limit,
 *              shown only). NO BUDGET SET MEANS NOTHING IS SENT: an Ai call spends real money, and a line nobody chose
 *              is not one this platform should guess.
 *   per role   zdz_llm_role_overrides: model (one of the slots' models), effort, cap_usd (0 < cap <= 5), provider
 *              ('poe' with an optional poe_model from the Model Registry's roster, or 'openai:<entry id>').
 *
 * The rules, each one a unit test:
 *   1  the key is read from the credentials layer per call, never logged, printed or answered; the answer must carry
 *      the business's configured workspace id (ZDZ_LLM_Anthropic), and with none configured nothing is sent
 *   2  the primary model for every role; the quick model for a call with less time left than the role's line (40 s
 *      until Ai checks measures it); overloaded / 5xx after one retry: the fallback model, or the quick model when time
 *      is short. A failure another model might not have (fails_over(): the network, 400 / 404 / 408 / 413 / 422, 429,
 *      5xx, 529; never 401 / 403, a key problem) goes to the fallback without retrying the same model when a retry
 *      would not help. A model the provider answered 404 for is remembered for an hour and skipped as a fallback
 *      (ZDZ_Model_Registry::mark_gone()). `web` uses a model that can search: the role's own, else the fallback or
 *      the quick model that can (ZDZ_Model_Registry::web_capable()). Never an automatic hop to another provider: the
 *      native provider down is "The Ai isn't reachable"
 *   3  inference_geo exactly as configured (absent when not); no anthropic-beta header, ever
 *   4  effort medium; high for `reason` with 40 s or more left; adaptive thinking, never returned as text
 *   5  privacy: every text part through ZDZ_Private::for( 'llm' )->encode() (gate codes cut); a last check codes again
 *      keeping the codes made and counts what it caught; answers come back through from_reader(), still coded;
 *      pictures and PDFs only from callers registered as reading documents, only on read_document. When the coder is
 *      not available NOTHING is sent: every provider is outside the site, and text that may carry customer details
 *      never leaves uncoded (fail closed)
 *   6  web only on `web`, and only for a short text-only question (web_safe())
 *   7  money: each try priced at its worst case first (fit()), max_output lowered to fit; caps per call ($1 by default),
 *      the site's month and the account's month (the business's budgets); bell alerts at 50% and 80% of the site's line
 *   8  cache marks: a byte-stable prefix -- nothing here adds a date or a name to it
 *   9  the shared device: refused unless the caller is registered kiosk-safe (none is)
 *  10  structured outputs on the native provider, and the JSON is always checked here too; a schema carrying customer
 *      data is refused (a provider may cache a schema for a day)
 *  11  never batches, the Files API, code execution, mcp_servers or dynamic filtering (ZDZ_LLM_Guard)
 *  12  a failed call is said as one, never an empty answer
 *  13  any registered caller may mark business text (system_company, a part's company); in it only the one-word name
 *      rules are off, so a full customer name, an e-mail, a phone, a street and a gate code are still coded and
 *      counted; a JSON string in the answer that is exactly one id-like word of that text comes back as written
 *      ("widget-std"). Each call draws the coder a new salt: one name, one code within a call and its retries
 *  14  $ctx['primary_only'] = true pins the call to the primary model on the native provider: no quick hop when time is
 *      short, no fallback (the try and its one retry are both the primary, then a failure said as one). A role an
 *      admin pointed at another provider is refused rather than sent there; an override naming another model is set
 *      aside. It only ever narrows: no caller gains anything by passing it. The answer's `stop` says whether it was cut
 *      off at the output limit
 *  15  $request['system_prefix']: the part of the instructions that is the same for everyone, every call; it goes first,
 *      as its own block with the 1-hour cache mark, and 'system' follows it unmarked. Coded under a salt fixed by its own
 *      words so it is the same bytes on every call; never carries the web; another provider gets prefix + system as one
 *      text. $request['cache_latest'] === false takes the role's 5-minute mark off the last message
 *  16  within a call each distinct sealed code (⟦e:<70-90 characters>⟧, about forty tokens) goes out as a short handle
 *      (⟦e:k7x2q⟧: 4 letters and digits and a mod-37 check character) and comes back as itself before the answer is read.
 *      The prefix's handles are made from the prefix itself; every other handle is random per call. A handle the call
 *      never gave out, or a slipped copy of one, stays a handle and from_reader() drops it
 *  17  $request['system_tier']: after the prefix, the part that is the same for everyone at one permission level, its own
 *      block with the 1-hour mark; coded and handled with salts fixed by its own words; refused on `web`; another
 *      provider gets prefix + tier + system as one text, in that order. With no prefix it becomes the prefix
 *
 * No app calls it yet; apps move to it one at a time and keep their current road until they do.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Core_LLM {

	const VERSION = '1.0.0';

	/** The Model Registry slots the router resolves its models through. */
	const SLOTS = array( 'primary' => 'llm_primary', 'quick' => 'llm_quick', 'fallback' => 'llm_fallback' );

	const SEARCH_USD   = 0.01;          // one web search, as the native provider bills it
	const SEARCH_READ  = 10000;         // tokens of results one search reads back, priced as input
	const MIN_OUT      = 256;           // fewer output tokens than this fit the money left: stop rather than call
	const MIN_SECONDS  = 8;             // less time than this left: stop rather than call
	const RETRY_PAUSE  = 1.0;           // seconds before the one retry (a worker is never held long)
	const HOLD_GRACE_S = 120;           // a hold outlives its call's deadline by this much, then stops counting
	const MAX_WAIT_S   = 600;           // no call waits longer than this, whatever deadline it was given
	const HIGH_EFFORT_S = 40;           // `reason` thinks harder only with this much time left
	const MAX_CAP_USD  = 5.0;           // the most an admin may set one call's cap to
	const MAX_BUDGET_USD = 1000000.0;   // a monthly line above this is a typing slip, not a budget
	const WEB_SAFE_MAX_CHARS = 4000;    // the most a web question may carry

	const ALERT_AT       = array( 50, 80 );   // per cent of the site's monthly line
	const ALERT_TYPE     = 'llm_spend';
	const ALERTS_OPTION  = 'zdz_llm_alerts_sent';
	const ROLES_OPTION   = 'zdz_llm_role_overrides';
	const LINES_OPTION   = 'zdz_llm_quick_lines';
	const ENTRIES_OPTION = 'zdz_llm_openai_entries';
	const PRICES_OPTION  = 'zdz_llm_model_prices';
	const CONN_OPTION    = 'zdz_llm_connection';
	const BUDGET_OPTION  = 'zdz_llm_budgets';

	const CODER = 'llm';                // ZDZ_Private::for( 'llm' )
	const OPEN  = '⟦';                  // the code format ZDZ_Private writes: ⟦c:<ledger id>⟧, ⟦e:<sealed>⟧
	const CLOSE = '⟧';

	/**
	 * The role table: effort, per-call cap (USD), pictures allowed, the quick-model time line (s), the output most calls
	 * of the role need, and whether the latest block gets a 5-minute cache mark (a conversation). The provider is the
	 * native one and the model is the primary slot's, unless an admin's override says otherwise.
	 */
	const ROLES = array(
		'classify'      => array( 'effort' => 'medium', 'cap_usd' => 1.0, 'pictures' => false, 'quick_line_s' => 40, 'max_output' => 4000,  'cache_latest' => false ),
		'extract'       => array( 'effort' => 'medium', 'cap_usd' => 1.0, 'pictures' => false, 'quick_line_s' => 40, 'max_output' => 6000,  'cache_latest' => false ),
		'read_document' => array( 'effort' => 'medium', 'cap_usd' => 1.0, 'pictures' => true,  'quick_line_s' => 40, 'max_output' => 8000,  'cache_latest' => false ),
		'write'         => array( 'effort' => 'medium', 'cap_usd' => 1.0, 'pictures' => false, 'quick_line_s' => 40, 'max_output' => 6000,  'cache_latest' => false ),
		'chat'          => array( 'effort' => 'medium', 'cap_usd' => 1.0, 'pictures' => false, 'quick_line_s' => 40, 'max_output' => 8000,  'cache_latest' => true ),
		'reason'        => array( 'effort' => 'high',   'cap_usd' => 1.0, 'pictures' => false, 'quick_line_s' => 40, 'max_output' => 16000, 'cache_latest' => true ),
		'web'           => array( 'effort' => 'medium', 'cap_usd' => 1.0, 'pictures' => false, 'quick_line_s' => 40, 'max_output' => 4000,  'cache_latest' => false ),
	);

	/**
	 * Who may call, by app id. documents: may send pictures and PDFs on read_document (reading them is that app's job).
	 * kiosk_safe: may be called for the shared device (none is). zdz-llm-checks is the Ai checks page's golden sets
	 * (made-up inputs only). No filter extends this list: a new picture-sending caller is a reviewed code change.
	 */
	const CALLERS = array(
		'commission-calculator' => array( 'documents' => false, 'kiosk_safe' => false ),
		'receipts'              => array( 'documents' => true,  'kiosk_safe' => false ),
		'stock'                 => array( 'documents' => true,  'kiosk_safe' => false ),
		'estimate-creator'      => array( 'documents' => true,  'kiosk_safe' => false ),
		'prep'                  => array( 'documents' => true,  'kiosk_safe' => false ),
		'knowledge-vault'       => array( 'documents' => true,  'kiosk_safe' => false ),
		'doorhangers'           => array( 'documents' => true,  'kiosk_safe' => false ),
		'sales-analytics'       => array( 'documents' => false, 'kiosk_safe' => false ),
		'leads'                 => array( 'documents' => false, 'kiosk_safe' => false ),
		'surveys'               => array( 'documents' => false, 'kiosk_safe' => false ),
		'dotplot'               => array( 'documents' => false, 'kiosk_safe' => false ),
		'inbox'                 => array( 'documents' => false, 'kiosk_safe' => false ),
		'hours'                 => array( 'documents' => true,  'kiosk_safe' => false ),
		'zdz-llm-checks'        => array( 'documents' => true,  'kiosk_safe' => false ),
	);

	/** Roles that carry business data: an OpenAI-compatible entry without checked terms cannot carry them. */
	const DATA_ROLES = array( 'classify', 'extract', 'read_document', 'write', 'chat', 'reason' );

	private static $instance = null;

	/** The one router. A suite may install a subclass (a fake clock, fake providers, a fake coder). */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function set_instance( $router ) {
		self::$instance = $router;
	}

	public static function ask( $role, array $request, array $ctx = array() ) {
		return self::instance()->handle( (string) $role, $request, $ctx );
	}

	public static function boot() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'zdz_register_alert_types', array( __CLASS__, 'register_alert_type' ) );
	}

	public static function register_alert_type( $router ) {
		if ( is_object( $router ) && method_exists( $router, 'register_type' ) ) {
			$router->register_type( self::ALERT_TYPE, array(
				'label'            => __( 'Ai spend this month', 'zorderz' ),
				'description'      => __( 'When the Ai spend reaches 50% and 80% of its monthly budget.', 'zorderz' ),
				'default_channels' => array( 'in_app' ),
				'source_plugin'    => 'zorderz',
				'category'         => 'system',
			) );
		}
	}

	/* ================================================================== configuration */

	/**
	 * The three models, from the Model Registry's slots ('' where a slot is empty).
	 *
	 * @return array{primary:string,quick:string,fallback:string}
	 */
	public static function models() {
		$out = array();
		foreach ( self::SLOTS as $k => $slot ) {
			$m = class_exists( 'ZDZ_Model_Registry' ) ? (string) ZDZ_Model_Registry::model_for( $slot ) : '';
			$out[ $k ] = trim( $m );
		}
		return $out;
	}

	/** The models the router may send to the native provider: the slots' models, nothing else. */
	public static function approved_models() {
		return array_values( array_unique( array_filter( array_values( self::models() ), 'strlen' ) ) );
	}

	/** Five non-negative numbers: [ in, cache write 5 min, cache write 1 h, cache read, out ]. */
	public static function valid_prices( $p ) {
		if ( ! is_array( $p ) || 5 !== count( $p ) ) {
			return false;
		}
		foreach ( $p as $x ) {
			if ( ! is_numeric( $x ) || (float) $x < 0 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The configured price table: model id => [ in, w5m, w1h, read, out ] USD per million tokens. Rows that are not
	 * five non-negative numbers are dropped. Ships empty.
	 */
	public static function price_table() {
		$t = function_exists( 'get_option' ) ? get_option( self::PRICES_OPTION, array() ) : array();
		$t = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_llm_model_prices', is_array( $t ) ? $t : array() ) : $t;
		$out = array();
		foreach ( is_array( $t ) ? $t : array() as $model => $p ) {
			if ( is_string( $model ) && '' !== trim( $model ) && self::valid_prices( $p ) ) {
				$out[ trim( $model ) ] = array_map( 'floatval', array_values( $p ) );
			}
		}
		return $out;
	}

	/** One model's prices, or null when none are configured (then the model is never called). */
	public static function prices_for( $model ) {
		$t = self::price_table();
		return $t[ (string) $model ] ?? null;
	}

	/**
	 * The native connection's region: [ 'inference_geo' => '' | a short region word, 'geo_price_factor' => float ].
	 * The factor applies only when a region is set, and is never below 1 (a region never makes a call cheaper here).
	 */
	public static function connection() {
		$c = function_exists( 'get_option' ) ? get_option( self::CONN_OPTION, array() ) : array();
		$c = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_llm_connection', is_array( $c ) ? $c : array() ) : $c;
		$c = is_array( $c ) ? $c : array();
		$geo = strtolower( trim( (string) ( $c['inference_geo'] ?? '' ) ) );
		$geo = preg_match( '/^[a-z]{2,12}$/', $geo ) ? $geo : '';
		$f   = isset( $c['geo_price_factor'] ) && is_numeric( $c['geo_price_factor'] ) ? (float) $c['geo_price_factor'] : 1.0;
		$f   = '' === $geo ? 1.0 : max( 1.0, min( 3.0, $f ) );
		return array( 'inference_geo' => $geo, 'geo_price_factor' => $f );
	}

	/** What the configured region costs over the list price (1.0 with no region). */
	public static function geo_factor() {
		return (float) self::connection()['geo_price_factor'];
	}

	/**
	 * The business's monthly lines, in USD. 'set' is false when no site line is configured: then nothing is sent.
	 * 'all' (the whole provider account) defaults to the site's line; 'ceiling' (shown only) defaults to 'all'.
	 *
	 * @return array{set:bool,app:float|null,all:float|null,ceiling:float|null}
	 */
	public static function budgets() {
		$b = function_exists( 'get_option' ) ? get_option( self::BUDGET_OPTION, array() ) : array();
		$b = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_llm_budgets', is_array( $b ) ? $b : array() ) : $b;
		$b = is_array( $b ) ? $b : array();
		$num = function ( $v ) {
			return ( is_numeric( $v ) && (float) $v >= 0 && (float) $v <= self::MAX_BUDGET_USD ) ? round( (float) $v, 2 ) : null;
		};
		$app = $num( $b['app'] ?? null );
		if ( null === $app ) {
			return array( 'set' => false, 'app' => null, 'all' => null, 'ceiling' => null );
		}
		$all = $num( $b['all'] ?? null );
		$all = null === $all ? $app : $all;
		$ceil = $num( $b['ceiling'] ?? null );
		$ceil = null === $ceil ? $all : max( $ceil, $all );
		return array( 'set' => true, 'app' => $app, 'all' => $all, 'ceiling' => $ceil );
	}

	/* ================================================================== the seams a suite swaps */

	/** The clock every deadline is measured on (public: the adapters read it too). A suite swaps it. */
	public function now() {
		return microtime( true );
	}

	protected function pause( $seconds ) {
		if ( $seconds > 0 ) {
			usleep( (int) ( $seconds * 1e6 ) );
		}
	}

	/** The provider for a resolved entry. */
	protected function provider( array $entry ) {
		$clock = array( $this, 'now' );
		if ( 'poe' === $entry['provider'] ) {
			return new ZDZ_LLM_Poe( null, $clock );
		}
		if ( 'openai' === $entry['provider'] ) {
			return new ZDZ_LLM_OpenAI_Compatible( $entry['entry_id'], $entry['entry'], null, $clock );
		}
		return new ZDZ_LLM_Anthropic( null, $clock );
	}

	/** The customer-detail coder, or null when it is not available (then nothing is sent). */
	protected function coder() {
		if ( ! class_exists( 'ZDZ_Private' ) || ! method_exists( 'ZDZ_Private', 'for' ) ) {
			return null;
		}
		$c = ZDZ_Private::for( self::CODER );
		return self::coder_ok( $c ) ? $c : null;
	}

	/** A coder the router can rely on: it codes going out and checks what comes back. */
	public static function coder_ok( $c ) {
		return is_object( $c ) && method_exists( $c, 'encode' ) && method_exists( $c, 'from_reader' );
	}

	/* ================================================================== the role table */

	/**
	 * The role's row: the table, the slots' models, then an admin's override (each value checked), then Ai checks'
	 * measured line. null for a role the router does not have.
	 */
	public static function role( $role ) {
		if ( ! isset( self::ROLES[ $role ] ) ) {
			return null;
		}
		$row             = self::ROLES[ $role ];
		$models          = self::models();
		$row['role']     = $role;
		$row['provider'] = 'anthropic';
		$row['model']    = $models['primary'];
		$ov  = function_exists( 'get_option' ) ? get_option( self::ROLES_OPTION, array() ) : array();
		$ov  = is_array( $ov ) && isset( $ov[ $role ] ) && is_array( $ov[ $role ] ) ? $ov[ $role ] : array();
		$row['override'] = false;
		if ( isset( $ov['model'] ) && is_string( $ov['model'] ) && in_array( $ov['model'], self::approved_models(), true ) ) {
			$row['model']    = $ov['model'];
			$row['override'] = true;
		}
		if ( isset( $ov['effort'] ) && in_array( $ov['effort'], array( 'medium', 'high' ), true ) ) {
			$row['effort']   = $ov['effort'];
			$row['override'] = true;
		}
		if ( isset( $ov['cap_usd'] ) && is_numeric( $ov['cap_usd'] ) && (float) $ov['cap_usd'] > 0 && (float) $ov['cap_usd'] <= self::MAX_CAP_USD ) {
			$row['cap_usd']  = (float) $ov['cap_usd'];
			$row['override'] = true;
		}
		$row['entry_id'] = '';
		$row['entry']    = array();
		if ( isset( $ov['provider'] ) ) {
			$p = (string) $ov['provider'];
			if ( 'poe' === $p ) {
				$row['provider'] = 'poe';
				$row['model']    = self::poe_model( isset( $ov['poe_model'] ) ? (string) $ov['poe_model'] : '' );
				$row['override'] = true;
			} elseif ( 0 === strpos( $p, 'openai:' ) ) {
				$id      = substr( $p, 7 );
				$entries = self::entries();
				if ( isset( $entries[ $id ] ) ) {
					$row['provider'] = 'openai';
					$row['entry_id'] = $id;
					$row['entry']    = $entries[ $id ];
					$row['model']    = (string) ( $entries[ $id ]['model'] ?? '' );
					$row['override'] = true;
				}
			}
		}
		if ( 'web' === $role && 'anthropic' === $row['provider'] && class_exists( 'ZDZ_Model_Registry' ) && method_exists( 'ZDZ_Model_Registry', 'web_capable' )
			&& '' !== $row['model'] && ! ZDZ_Model_Registry::web_capable( $row['model'] ) ) {
			// rule 2: a model without web search never takes `web`; the slot that can search answers it
			$can = '';
			foreach ( array( $models['fallback'], $models['quick'] ) as $m ) {
				if ( '' !== $m && ZDZ_Model_Registry::web_capable( $m ) && ! ZDZ_Model_Registry::is_gone( $m, 'anthropic' ) ) {
					$can = $m;
					break;
				}
			}
			$row['model'] = $can;
		}
		$lines = function_exists( 'get_option' ) ? get_option( self::LINES_OPTION, array() ) : array();
		if ( is_array( $lines ) && isset( $lines[ $role ] ) && (int) $lines[ $role ] >= 15 && (int) $lines[ $role ] <= 90 ) {
			$row['quick_line_s']  = (int) $lines[ $role ];
			$row['line_measured'] = true;
		} else {
			$row['line_measured'] = false;
		}
		return $row;
	}

	/**
	 * The Poe handle for a role pointed at Poe: the override's handle when it is on the Model Registry's roster, else
	 * the gateway's base model. '' when neither is configured (then the call is refused).
	 */
	public static function poe_model( $wanted ) {
		$wanted = trim( (string) $wanted );
		if ( '' !== $wanted && class_exists( 'ZDZ_Model_Registry' ) ) {
			$roster = ZDZ_Model_Registry::choices();
			$names  = array();
			foreach ( $roster as $k => $v ) {
				$names[] = is_string( $k ) ? $k : (string) $v;
			}
			if ( in_array( $wanted, $names, true ) ) {
				return $wanted;
			}
		}
		return class_exists( 'ZDZ_Model_Registry' ) ? trim( (string) ZDZ_Model_Registry::base_model() ) : '';
	}

	public static function entries() {
		$e   = function_exists( 'get_option' ) ? get_option( self::ENTRIES_OPTION, array() ) : array();
		$out = array();
		foreach ( is_array( $e ) ? $e : array() as $id => $row ) {
			$id = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $id ) );
			if ( '' !== $id && is_array( $row ) ) {
				$out[ $id ] = $row;
			}
		}
		return $out;
	}

	/* ================================================================== money */

	/** USD per million tokens for a model on a provider, or null when unknown (then that model is not tried). */
	private static function rates( $provider, $model ) {
		$p = $provider->prices( $model );
		return self::valid_prices( $p ) ? array_map( 'floatval', array_values( $p ) ) : null;
	}

	/** What a finished try cost. A provider that prices itself (Poe: 0) is taken at its word. */
	public static function cost( array $rates, array $u, $geo = true ) {
		$t = ( $u['in'] * $rates[0] + $u['cache_write_5m'] * $rates[1] + $u['cache_write_1h'] * $rates[2] + $u['cache_read'] * $rates[3] + $u['out'] * $rates[4] ) / 1e6;
		return $t * ( $geo ? self::geo_factor() : 1.0 ) + $u['searches'] * self::SEARCH_USD;
	}

	/**
	 * A cautious count of the prompt's tokens: a token per 3 bytes of text, and a picture or a PDF page at a fixed
	 * generous size (their base64 would otherwise count as text and overstate them twenty-fold).
	 */
	public static function estimate_tokens( array $n ) {
		$bytes = strlen( (string) ( $n['system'] ?? '' ) ) + strlen( (string) ( $n['system_prefix'] ?? '' ) ) + strlen( (string) ( $n['system_tier'] ?? '' ) );
		$fixed = 0;
		foreach ( (array) ( $n['messages'] ?? array() ) as $m ) {
			foreach ( (array) ( $m['content'] ?? array() ) as $p ) {
				$t = $p['type'] ?? '';
				if ( 'text' === $t ) {
					$bytes += strlen( (string) $p['text'] );
				} elseif ( 'image' === $t ) {
					$fixed += 5000;
				} elseif ( 'pdf' === $t ) {
					// compressed object streams hide the /Type /Page objects; then price by size, a page per 1,500 bytes,
					// up to the API's 100 pages
					$pages = self::pdf_pages( (string) $p['data'] );
					if ( $pages < 1 ) {
						$pages = (int) min( 100, max( 1, ceil( strlen( (string) $p['data'] ) * 0.75 / 1500 ) ) );
					}
					$fixed += $pages * 6000 + (int) ( strlen( (string) ( $p['text'] ?? '' ) ) / 3 );
				}
				$bytes += 40;
			}
		}
		if ( ! empty( $n['json_schema'] ) ) {
			$bytes += strlen( (string) wp_json_encode( $n['json_schema'] ) ) + 300; // the schema's grammar prompt
		}
		return (int) ( $bytes / 3 ) + 1 + $fixed + 50;
	}

	/** Pages in a base64 PDF (its /Type /Page objects), 0 when it cannot tell. */
	public static function pdf_pages( $b64 ) {
		$raw = base64_decode( $b64, true );
		if ( false === $raw ) {
			return 0;
		}
		return (int) preg_match_all( '#/Type\s*/Page(?![a-zA-Z])#', $raw );
	}

	/**
	 * Price this try's worst case (the whole prompt at the 1-hour cache-write rate, every search it may make, and
	 * max_output of output, thinking included), lower max_output to what the money left allows.
	 * -> [ max_output, worst_usd ] or null when fewer than MIN_OUT tokens fit.
	 */
	public static function fit( array $n, array $rates, $left_usd ) {
		$geo    = self::geo_factor();
		$tokens = self::estimate_tokens( $n );
		$in_usd = $tokens * $rates[2] / 1e6 * $geo;
		$web    = 0.0;
		if ( ! empty( $n['web'] ) ) {
			$web = ZDZ_LLM_Anthropic::WEB_USES * ( self::SEARCH_USD + ( self::SEARCH_READ * $rates[0] + $tokens * $rates[3] ) / 1e6 * $geo );
		}
		$per_out = $rates[4] / 1e6 * $geo;
		$room    = $left_usd - $in_usd - $web;
		if ( $per_out <= 0 ) {
			$fits = $room >= 0 ? PHP_INT_MAX : 0;   // a model whose output is free: only the input has to fit
		} else {
			$fits = $room > 0 ? (int) floor( $room / $per_out ) : 0;
		}
		$want = max( 1, (int) $n['max_output'] );
		if ( $fits < min( self::MIN_OUT, $want ) ) {
			return null;
		}
		$out = min( $want, $fits );
		return array( $out, $in_usd + $web + $out * $per_out );
	}

	/* ================================================================== the request */

	/** Parts of a message, as given: a string becomes one text part. Unknown parts are kept as 'unknown' -> refused. */
	private static function parts( $content ) {
		if ( is_string( $content ) ) {
			return '' === trim( $content ) ? array() : array( array( 'type' => 'text', 'text' => $content ) );
		}
		$out = array();
		if ( ! is_array( $content ) ) {
			return $out;                       // null, a number, an object: nothing (refused as an empty request)
		}
		foreach ( $content as $p ) {
			if ( ! is_array( $p ) ) {
				$out[] = array( 'type' => 'unknown' );
				continue;
			}
			$t = $p['type'] ?? '';
			if ( 'text' === $t ) {
				if ( ! is_string( $p['text'] ?? null ) ) {
					$out[] = array( 'type' => 'unknown' );   // never an "Array to string" warning or a fatal error
					continue;
				}
				if ( '' === trim( $p['text'] ) ) {
					continue;                               // an empty block (with a cache mark, the API's 400)
				}
				$q = array( 'type' => 'text', 'text' => $p['text'] );
				if ( '1h' === ( $p['cache'] ?? null ) ) {
					$q['cache'] = '1h';
				}
				if ( true === ( $p['company'] ?? null ) ) {
					$q['company'] = true;                    // words the business wrote (rule 13)
				}
				$out[] = $q;
			} elseif ( 'image' === $t ) {
				$mt    = strtolower( (string) ( $p['media_type'] ?? '' ) );
				$out[] = array( 'type' => 'image', 'media_type' => $mt, 'data' => preg_replace( '/\s+/', '', (string) ( $p['data'] ?? '' ) ) );
			} elseif ( 'pdf' === $t ) {
				$out[] = array( 'type' => 'pdf', 'data' => preg_replace( '/\s+/', '', (string) ( $p['data'] ?? '' ) ), 'text' => (string) ( $p['text'] ?? '' ) );
			} else {
				$out[] = array( 'type' => 'unknown' );
			}
		}
		return $out;
	}

	/** Every string in a schema (keys, descriptions, enums), for the customer-data check. */
	private static function schema_strings( $v, array &$out ) {
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				if ( is_string( $k ) ) {
					$out[] = $k;
				}
				self::schema_strings( $x, $out );
			}
		} elseif ( is_string( $v ) ) {
			$out[] = $v;
		}
	}

	/* ------------------------------------------------------------------ the code format (ZDZ_Private's) */

	/** Any ⟦ ⟧ in words is defused, so no one can hand the site a code. */
	public static function defuse( $s ) {
		return str_replace( array( self::OPEN, self::CLOSE ), array( '[', ']' ), (string) $s );
	}

	/** Text with its codes taken out. */
	public static function on_plain_text( $text ) {
		return (string) preg_replace( '/' . self::OPEN . '[ce]:[A-Za-z0-9_\-]*' . self::CLOSE . '/u', ' ', (string) $text );
	}

	/** The ⟦e:…⟧ codes in a text. */
	public static function sealed_codes( $text ) {
		preg_match_all( '/' . self::OPEN . 'e:([A-Za-z0-9_\-]+)' . self::CLOSE . '/u', (string) $text, $m );
		return $m[1];
	}

	/** The ⟦c:…⟧ codes in a text. */
	public static function ledger_codes( $text ) {
		preg_match_all( '/' . self::OPEN . 'c:(\d+)' . self::CLOSE . '/u', (string) $text, $m );
		return array_map( 'intval', $m[1] );
	}

	/** How many codes a text holds (⟦c⟧ and ⟦e⟧, and "[… code sealed]" cuts). */
	public static function count_codes( $text ) {
		$n  = (int) preg_match_all( '/' . self::OPEN . '[ce]:[A-Za-z0-9_\-]*' . self::CLOSE . '/u', (string) $text );
		$n += (int) preg_match_all( '/\[[a-z ]*code sealed\]/', (string) $text );
		return $n;
	}

	private function refuse( $code, $msg, array $row ) {
		$row['outcome'] = 'refused_' . $code;
		$this->ledger( $row );
		return new WP_Error( 'zdz_llm_' . $code, $msg, array( 'status' => 0, 'kind' => 'refused_' . $code ) );
	}

	/* ================================================================== the call */

	public function handle( $role, array $request, array $ctx ) {
		// Each call has its own "my row was written" slot, so a call made while another waits (from a hook, during the
		// HTTP request or an alert) can't mark the waiting call's row as written.
		$this->written[] = false;
		try {
			return $this->handle_call( $role, $request, $ctx );
		} finally {
			array_pop( $this->written );
		}
	}

	/** Whether THIS call's ledger row was written (the slot handle() opened for it). */
	private function mine_written() {
		return $this->written ? (bool) end( $this->written ) : false;
	}

	private function handle_call( $role, array $request, array $ctx ) {
		$t0     = $this->now();
		$caller = (string) ( $ctx['caller'] ?? '' );
		$row    = array( 'caller' => isset( self::CALLERS[ $caller ] ) ? $caller : 'other', 'role' => isset( self::ROLES[ $role ] ) ? $role : 'other', 'provider' => '', 'model' => '' );

		// ---- who is asking, and for what
		$r = self::role( $role );
		if ( ! $r ) {
			return $this->refuse( 'role', __( 'The Ai was asked for something it does not do.', 'zorderz' ), $row );
		}
		if ( ! isset( self::CALLERS[ $caller ] ) ) {
			return $this->refuse( 'caller', __( 'This app is not registered to use the Ai.', 'zorderz' ), $row );
		}
		$who     = self::CALLERS[ $caller ];
		$uid     = (int) ( $ctx['user_id'] ?? 0 );
		$session = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( empty( $who['kiosk_safe'] ) && ( ( $uid > 0 && self::is_kiosk( $uid ) ) || ( $session > 0 && self::is_kiosk( $session ) ) ) ) {
			// the shared device's own session is refused too, whatever user_id the caller passes
			return $this->refuse( 'kiosk', __( 'The Ai is not available on the shared device.', 'zorderz' ), $row );
		}
		// rule 14: the primary model on the native provider, or nothing
		if ( ! empty( $ctx['primary_only'] ) ) {
			if ( 'anthropic' !== $r['provider'] ) {
				return $this->refuse( 'primary_only', __( 'This app uses only the primary model on the site\'s own key, and the Ai for this is set to another provider.', 'zorderz' ), $row );
			}
			$r['model']        = self::models()['primary'];
			$r['primary_only'] = true;
		}
		$row['provider'] = $r['provider'];
		$row['model']    = self::model_code( $r['model'] );
		if ( '' === (string) $r['model'] ) {
			return $this->refuse( 'model', __( 'No Ai model is set for this yet. An admin sets the models for the Ai on the Ai usage page.', 'zorderz' ), $row );
		}

		// ---- the request, in the neutral shape
		if ( isset( $request['system'] ) && ! is_string( $request['system'] ) ) {
			return $this->refuse( 'request', __( 'The Ai was given instructions it cannot read.', 'zorderz' ), $row );
		}
		$system = (string) ( $request['system'] ?? '' );
		// rule 15: the instructions' fixed part, cached on its own
		if ( isset( $request['system_prefix'] ) && ! is_string( $request['system_prefix'] ) ) {
			return $this->refuse( 'request', __( 'The Ai was given instructions it cannot read.', 'zorderz' ), $row );
		}
		$prefix = (string) ( $request['system_prefix'] ?? '' );
		// rule 17: a second cached block -- what is the same for everyone at one permission level
		if ( isset( $request['system_tier'] ) && ! is_string( $request['system_tier'] ) ) {
			return $this->refuse( 'request', __( 'The Ai was given instructions it cannot read.', 'zorderz' ), $row );
		}
		$tier = (string) ( $request['system_tier'] ?? '' );
		if ( '' === $prefix && '' !== $tier ) {
			$prefix = $tier;   // no first block: the second becomes it
			$tier   = '';
			$request['system_prefix_company'] = $request['system_tier_company'] ?? null;
		}
		$messages = array();
		foreach ( is_array( $request['messages'] ?? null ) ? $request['messages'] : array() as $m ) {
			if ( ! is_array( $m ) ) {
				return $this->refuse( 'request', __( 'The Ai was given a message it cannot read.', 'zorderz' ), $row );
			}
			$parts = self::parts( $m['content'] ?? null );
			if ( ! $parts ) {
				return $this->refuse( 'request', __( 'The Ai was given an empty message.', 'zorderz' ), $row );
			}
			$messages[] = array( 'role' => 'assistant' === ( $m['role'] ?? '' ) ? 'assistant' : 'user', 'content' => $parts );
		}
		if ( ! $messages || 'user' !== end( $messages )['role'] ) {
			return $this->refuse( 'request', __( 'The Ai was given nothing to answer.', 'zorderz' ), $row );
		}
		$schema   = isset( $request['json_schema'] ) && is_array( $request['json_schema'] ) && $request['json_schema'] ? $request['json_schema'] : null;
		$web      = 'web' === $role;
		$pictures = false;
		foreach ( $messages as $m ) {
			foreach ( $m['content'] as $p ) {
				if ( 'unknown' === $p['type'] ) {
					return $this->refuse( 'request', __( 'The Ai was sent a part it does not take.', 'zorderz' ), $row );
				}
				if ( 'image' === $p['type'] || 'pdf' === $p['type'] ) {
					$pictures = true;
				}
				if ( 'image' === $p['type'] && ! in_array( $p['media_type'], array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
					return $this->refuse( 'request', __( 'The Ai takes JPEG, PNG, GIF or WebP pictures (convert HEIC first).', 'zorderz' ), $row );
				}
			}
		}

		// ---- rule 6: web only on `web`, and only a short text-only question
		if ( $web && ( '' !== $prefix || '' !== $tier ) ) {
			return $this->refuse( 'web', __( 'Web search carries a short question only, never business data.', 'zorderz' ), $row );   // rule 15
		}
		if ( ! empty( $request['web'] ) && ! $web ) {
			return $this->refuse( 'web', __( 'Web search is only for a question on its own.', 'zorderz' ), $row );
		}
		if ( $web ) {
			$flat = array();
			if ( '' !== $system ) {
				$flat[] = array( 'role' => 'system', 'content' => $system );
			}
			foreach ( $messages as $m ) {
				$only = true;
				$txt  = '';
				foreach ( $m['content'] as $p ) {
					if ( 'text' !== $p['type'] ) {
						$only = false;
					} else {
						$txt .= $p['text'];
					}
				}
				$flat[] = array( 'role' => $m['role'], 'content' => $only ? $txt : array( 'not text' ) );
			}
			if ( ! self::web_safe( $flat ) || $schema ) {
				return $this->refuse( 'web', __( 'Web search carries a short question only, never business data.', 'zorderz' ), $row );
			}
		}

		// ---- rule 5: pictures and PDFs only from a document reader, only on read_document
		if ( $pictures ) {
			$row['picture_sent'] = 0;
			if ( 'read_document' !== $role || empty( $who['documents'] ) || empty( $r['pictures'] ) ) {
				return $this->refuse( 'picture', __( 'This app may not send pictures or documents to the Ai.', 'zorderz' ), $row );
			}
		}

		// ---- the provider entry
		$entry = array( 'provider' => $r['provider'], 'entry_id' => $r['entry_id'], 'entry' => $r['entry'] );
		$prov  = $this->provider( $entry );
		$caps  = $prov->capabilities();
		if ( 'openai' === $r['provider'] ) {
			if ( in_array( $role, self::DATA_ROLES, true ) && ( ! method_exists( $prov, 'terms_checked' ) || ! $prov->terms_checked() ) ) {
				return $this->refuse( 'terms', __( 'This provider\'s data terms have not been checked, so it cannot carry business data.', 'zorderz' ), $row );
			}
		}
		if ( 'poe' !== $r['provider'] && null === self::rates( $prov, $r['model'] ) ) {
			// an unknown price can't be capped (a dear model would pass the cap many times over)
			return $this->refuse( 'prices', __( 'There are no prices for this Ai model, so its cost can\'t be capped. An admin adds them on the Ai usage page.', 'zorderz' ), $row );
		}
		if ( $web && empty( $caps['web'] ) ) {
			return $this->refuse( 'web', __( 'The Ai chosen for this has no web search.', 'zorderz' ), $row );
		}
		if ( $pictures && empty( $caps['images'] ) ) {
			return $this->refuse( 'picture', __( 'The Ai chosen for this cannot read pictures.', 'zorderz' ), $row );
		}

		// ---- rule 5: no coder, nothing sent (every provider is outside the site)
		$coder = $this->coder();
		if ( ! self::coder_ok( $coder ) ) {
			return $this->refuse( 'coder', __( 'Private details can\'t be coded right now, so nothing was sent to the Ai.', 'zorderz' ), $row );
		}

		// rule 15: only the native provider takes the prefix as its own block; any other gets one text, prefix first
		if ( '' !== $prefix && 'anthropic' !== $r['provider'] ) {
			$system = $prefix . ( '' !== $tier ? "\n\n" . $tier : '' ) . ( '' !== $system ? "\n\n" . $system : '' );
			$prefix = '';
			$tier   = '';   // rule 17: one text, in the same order
		}

		// ---- rule 5: code every text part before it leaves
		// rule 13: each call draws a new salt, so a name gets one code within this call (and its retries, which reuse
		// what was coded here) and another code in the next call, in a long cron or CLI run too
		if ( method_exists( $coder, 'new_request' ) ) {
			$coder->new_request();
		}
		$fixed_salts = method_exists( $coder, 'fixed_salt' ) && method_exists( $coder, 'restore_salt' );
		$coded  = 0;
		$co     = array( 'company' => true );                     // rule 13
		$sys_o  = true === ( $request['system_company'] ?? null ) ? $co : array();
		$system = '' === $system ? '' : $coder->encode( $system, null, false, null, $sys_o );
		$coded += self::count_codes( $system );
		$pre_o  = true === ( $request['system_prefix_company'] ?? null ) ? $co : array();   // rule 15
		// the prefix codes the same on every call -- a salt fixed by its own words -- or the cache is never read
		$pre_seed = '' !== $prefix && $fixed_salts ? hash( 'sha256', $prefix ) : '';
		$pre_salt = '' !== $pre_seed ? $coder->fixed_salt( $pre_seed ) : null;
		$prefix   = '' === $prefix ? '' : $coder->encode( $prefix, null, false, null, $pre_o );
		if ( '' !== $pre_seed ) {
			$coder->restore_salt( $pre_salt );
		}
		$coded += self::count_codes( $prefix );
		// rule 17: the second block codes the same on every call too -- a salt fixed by its own words
		$tier_o    = true === ( $request['system_tier_company'] ?? null ) ? $co : array();
		$tier_seed = '' !== $tier && $fixed_salts ? hash( 'sha256', 'tier|' . $tier ) : '';
		$tier_salt = '' !== $tier_seed ? $coder->fixed_salt( $tier_seed ) : null;
		$tier      = '' === $tier ? '' : $coder->encode( $tier, null, false, null, $tier_o );
		if ( '' !== $tier_seed ) {
			$coder->restore_salt( $tier_salt );
		}
		$coded += self::count_codes( $tier );
		foreach ( $messages as $i => $m ) {
			foreach ( $m['content'] as $j => $p ) {
				if ( 'text' === $p['type'] ) {
					$messages[ $i ]['content'][ $j ]['text'] = $coder->encode( $p['text'], null, false, null, empty( $p['company'] ) ? array() : $co );
					$coded += self::count_codes( $messages[ $i ]['content'][ $j ]['text'] );
				} elseif ( 'pdf' === $p['type'] && '' !== $p['text'] ) {
					$messages[ $i ]['content'][ $j ]['text'] = $coder->encode( $p['text'] );
				}
			}
		}
		// rule 10: a schema may be cached by the provider for a day, so it may carry nothing a coder would code
		if ( $schema ) {
			$strings = array();
			self::schema_strings( $schema, $strings );
			foreach ( $strings as $s ) {
				if ( $coder->encode( $s ) !== self::defuse( $s ) ) {
					return $this->refuse( 'schema', __( 'The answer shape carried private details, so nothing was sent.', 'zorderz' ), $row );
				}
			}
		}
		// the last check: code again over everything that will be sent, keeping the codes made; count what it newly caught
		$caught  = 0;
		$again   = $coder->encode( $system, null, true, null, $sys_o );
		$caught += max( 0, self::count_codes( $again ) - self::count_codes( $system ) );
		$system  = $again;
		if ( '' !== $prefix ) {   // rule 15
			$pre_salt = '' !== $pre_seed ? $coder->fixed_salt( $pre_seed ) : null;   // the same fixed salt
			$again    = $coder->encode( $prefix, null, true, null, $pre_o );
			if ( '' !== $pre_seed ) {
				$coder->restore_salt( $pre_salt );
			}
			$caught += max( 0, self::count_codes( $again ) - self::count_codes( $prefix ) );
			$prefix  = $again;
		}
		if ( '' !== $tier ) {   // rule 17
			$tier_salt = '' !== $tier_seed ? $coder->fixed_salt( $tier_seed ) : null;
			$again     = $coder->encode( $tier, null, true, null, $tier_o );
			if ( '' !== $tier_seed ) {
				$coder->restore_salt( $tier_salt );
			}
			$caught += max( 0, self::count_codes( $again ) - self::count_codes( $tier ) );
			$tier    = $again;
		}
		foreach ( $messages as $i => $m ) {
			foreach ( $m['content'] as $j => $p ) {
				if ( 'text' === $p['type'] || ( 'pdf' === $p['type'] && '' !== $p['text'] ) ) {
					$again   = $coder->encode( $p['text'], null, true, null, ( 'text' === $p['type'] && ! empty( $p['company'] ) ) ? $co : array() );
					$caught += max( 0, self::count_codes( $again ) - self::count_codes( $p['text'] ) );
					$messages[ $i ]['content'][ $j ]['text'] = $again;
				}
			}
		}
		// rule 13: the words of business text as sent. A JSON string in the answer that is exactly one of them
		// ("widget-std") is the model naming a business thing, so it comes back as written, not coded again
		$company_words = array();
		$company_texts = '' !== $system && $sys_o ? array( $system ) : array();
		if ( '' !== $prefix && $pre_o ) {
			$company_texts[] = $prefix;   // rule 15
		}
		if ( '' !== $tier && $tier_o ) {
			$company_texts[] = $tier;     // rule 17
		}
		foreach ( $messages as $m ) {
			foreach ( $m['content'] as $p ) {
				if ( 'text' === $p['type'] && ! empty( $p['company'] ) ) {
					$company_texts[] = $p['text'];
				}
			}
		}
		foreach ( $company_texts as $t ) {
			if ( preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}_\-]*/u', self::on_plain_text( $t ), $wm ) ) {
				foreach ( $wm[0] as $w ) {
					$company_words[ $w ] = true;
				}
			}
		}
		$allowed_c = array();
		$allowed_e = array();
		foreach ( array_merge( array( $prefix, $tier, $system ), self::texts( $messages ) ) as $t ) {
			$allowed_c = array_merge( $allowed_c, self::ledger_codes( $t ) );
			$allowed_e = array_merge( $allowed_e, self::sealed_codes( $t ) );
		}
		// rule 16: a sealed code is 70-90 random characters, about forty tokens each, and a chat turn's data carries
		// hundreds. Within this call each distinct one goes out as a short handle (⟦e:k7x2q⟧) and comes back as itself
		// before anything reads the answer. The prefix's handles are made from the prefix, so it stays the same bytes on
		// every call (rule 15's cache); the rest are random per call. A handle carries nothing of the detail.
		$handles = self::handles( $prefix, $system, $messages, $tier );
		if ( $handles ) {
			$prefix = self::to_handles( $prefix, $handles );
			$tier   = self::to_handles( $tier, $handles );   // rule 17
			$system = self::to_handles( $system, $handles );
			foreach ( $messages as $i => $m ) {
				foreach ( $m['content'] as $j => $p ) {
					if ( isset( $p['text'] ) && is_string( $p['text'] ) && '' !== $p['text'] ) {
						$messages[ $i ]['content'][ $j ]['text'] = self::to_handles( $p['text'], $handles );
					}
				}
			}
		}
		$row['coded_count']  = $coded + $caught;
		$row['caught_count'] = $caught;
		$row['picture_sent'] = $pictures ? 1 : 0;

		// ---- the deadline
		$deadline = isset( $ctx['deadline'] ) && is_numeric( $ctx['deadline'] ) ? (float) $ctx['deadline'] : $t0 + 60;
		$deadline = min( $deadline, $t0 + self::MAX_WAIT_S );   // a deadline in milliseconds, or years away

		// ---- rule 7: the month's money. The call BOOKS the most it may cost before anything is sent (a hold), so two
		// calls at once can't both spend the same headroom. Released once its real cost is in the ledger (the finally
		// below); a hold left by a process that died expires after the deadline plus a grace.
		$budgets = self::budgets();
		if ( ! $budgets['set'] ) {
			return $this->refuse( 'budget', __( 'No monthly Ai budget is set, so nothing was sent. An admin sets one on the Ai usage page.', 'zorderz' ), $row );
		}
		$cap = (float) $r['cap_usd'];
		if ( isset( $ctx['cap_usd'] ) && is_numeric( $ctx['cap_usd'] ) && (float) $ctx['cap_usd'] > 0 ) {
			$cap = min( $cap, (float) $ctx['cap_usd'] );
		}
		$lines = array( 'app' => (int) round( $budgets['app'] * 1000 ), 'all' => (int) round( $budgets['all'] * 1000 ) );
		$hold  = ZDZ_LLM_Ledger::maybe_install()
			? ZDZ_LLM_Ledger::reserve( $cap, $lines, true, max( 0, $deadline - $this->now() ) + self::HOLD_GRACE_S )
			: null;
		if ( null === $hold ) {
			return $this->refuse( 'ledger', __( 'The Ai\'s spend can\'t be read right now, so nothing was sent.', 'zorderz' ), $row );
		}
		if ( $hold['usd'] <= 0 ) {
			return $this->refuse( 'month', __( 'The Ai\'s budget for this month is used up.', 'zorderz' ), $row );
		}
		$budget = min( $cap, (float) $hold['usd'] );
		try {
			return $this->tries( $role, $r, $prov, $row, array(
				'system'        => $system,
				'prefix'        => $prefix,
				'tier'          => $tier,       // rule 17
				'messages'      => $messages,
				'schema'        => $schema,
				'web'           => $web,
				'request'       => $request,
				'coder'         => $coder,
				'allowed'       => array( $allowed_c, $allowed_e ),
				'handles'       => $handles,    // rule 16
				'company_words' => $company_words,
				'pictures'      => $pictures,
			), $budget, $deadline, $t0 );
		} catch ( Throwable $e ) {
			// Something threw while a try may have been out. Still one ledger row, counted at the whole booking (its
			// worst), so the spend is seen by every process after the hold is released; then on up.
			if ( ! $this->mine_written() ) {
				$this->ledger( array_merge( $row, array(
					'usd_milli' => (int) ceil( $budget * 1000 ),
					'ms'        => (int) round( ( $this->now() - $t0 ) * 1000 ),
					'outcome'   => 'error_exception',
				) ) );
			}
			throw $e;
		} finally {
			// Released only when this call's own ledger row was written: otherwise its spend isn't in the ledger, and the
			// hold keeps counting it until it expires.
			if ( $this->mine_written() ) {
				ZDZ_LLM_Ledger::release( $hold['id'] );
			}
		}
	}

	/** The tries of one call, inside its hold. Every way out writes the call's one ledger row first. */
	private function tries( $role, array $r, $prov, array $row, array $in, $budget, $deadline, $t0 ) {
		$system    = $in['system'];
		$messages  = $in['messages'];
		$schema    = $in['schema'];
		$web       = $in['web'];
		$request   = $in['request'];
		$coder     = $in['coder'];
		$allowed_c = $in['allowed'][0];
		$allowed_e = $in['allowed'][1];
		$pictures  = $in['pictures'];

		$max_out = isset( $request['max_output'] ) && (int) $request['max_output'] > 0 ? min( (int) $request['max_output'], (int) $r['max_output'] * 2, 32000 ) : (int) $r['max_output'];
		$base    = array(
			'system'        => $system,
			'system_prefix' => (string) ( $in['prefix'] ?? '' ),   // rule 15
			'system_tier'   => (string) ( $in['tier'] ?? '' ),     // rule 17
			'messages'      => $messages,
			'json_schema'   => $schema,
			'web'           => $web,
			'max_output'    => $max_out,
			'cache_latest'  => ! empty( $r['cache_latest'] ) && false !== ( $request['cache_latest'] ?? null ),   // rule 15
		);

		// ---- the tries: primary, one retry, one fallback (rule 2), each fitted for time and money first (rule 7)
		$spent    = 0.0;
		$usage    = ZDZ_LLM_Wire::zero_usage();
		$tries    = 0;
		$fallback = false;
		$first    = 'anthropic' === $r['provider'] ? $this->pick( $r, $deadline ) : $r['model'];
		if ( 'poe' === $r['provider'] && $deadline - $this->now() < ZDZ_LLM_Poe::MIN_BUDGET_S ) {
			$first = null;            // the Poe gateway never waits less than 15 s, so it would pass the deadline
		}
		if ( null === $first ) {
			return $this->refuse( 'time', __( 'There was not enough time left to ask the Ai.', 'zorderz' ), $row );
		}
		$plan  = array( $first, $first );          // the try, then its one retry
		$last  = null;
		$model = $first;
		for ( $k = 0; $k < 3; $k++ ) {
			if ( 2 === $k ) {
				// the fallback, only after a failure another model might not have (never a key problem)
				if ( 'anthropic' !== $r['provider'] || ! $last || ! self::fails_over( $last ) ) {
					break;
				}
				$next = $this->fallback_model( $model, $r, $deadline );
				if ( null === $next ) {
					break;           // $model keeps the model that failed, so the row names it
				}
				$model    = $next;
				$fallback = true;
			} elseif ( 1 === $k ) {
				// the Poe gateway was asked not to fail over; everything gets one retry, Poe none
				if ( ! $last || 'poe' === $r['provider'] ) {
					break;
				}
				if ( ! self::retryable( $last ) ) {
					if ( self::fails_over( $last ) ) {
						continue;   // the same model again would not help (missing, body refused): straight to the fallback
					}
					break;
				}
				$d     = $last->get_error_data();
				$pause = min( 3.0, max( self::RETRY_PAUSE, (float) ( is_array( $d ) ? ( $d['retry_after'] ?? 0 ) : 0 ) ) );
				if ( $deadline - $this->now() - $pause < self::MIN_SECONDS ) {
					// no time for the same model again: straight to the fallback, if one fits
					continue;
				}
				$this->pause( $pause );
				// the same model again, unless the time left has fallen under the role's line (then the quick model)
				$again = 'anthropic' === $r['provider'] ? $this->pick( $r, $deadline ) : $plan[1];
				$model = null === $again ? $model : $again;
			}
			$left_s = $deadline - $this->now();
			if ( $left_s < self::MIN_SECONDS ) {
				$last = $last ? $last : ZDZ_LLM_Wire::error( 'network' );
				break;
			}
			$n           = $base + array( 'model' => $model );
			$n['effort'] = $this->effort( $role, $r, $left_s );
			if ( 'poe' === $r['provider'] ) {
				$rates = null;
				$fit   = array( $n['max_output'], 0.0 );
			} else {
				$rates = self::rates( $prov, $model );
				if ( null === $rates ) {
					if ( $tries > 0 && $last ) {
						break;   // a model with no prices is never tried; the earlier failure is the answer
					}
					$row['model'] = self::model_code( $model );
					return $this->finish_refusal( 'prices', __( 'There are no prices for this Ai model, so its cost can\'t be capped. An admin adds them on the Ai usage page.', 'zorderz' ), $row, $usage, $spent, $tries, $fallback, $t0 );
				}
				$fit = self::fit( $n, $rates, $budget - $spent );
			}
			if ( null === $fit ) {
				if ( $tries > 0 && $last ) {
					break; // an earlier try failed and left too little to try again: that failure is the answer
				}
				$row['model'] = self::model_code( $model );
				return $this->finish_refusal( 'money', __( 'This would cost more than the Ai may spend on it.', 'zorderz' ), $row, $usage, $spent, $tries, $fallback, $t0 );
			}
			$n['max_output'] = $fit[0];
			$tries++;
			$res = $prov->send( $n, $deadline );
			if ( is_wp_error( $res ) && 'poe' === $r['provider'] && ! isset( ( (array) $res->get_error_data() )['kind'] ) ) {
				// a gateway's own words never reach a page; its HTTP status gives the kind
				$st  = is_numeric( $res->get_error_data() ) ? (int) $res->get_error_data() : 0;
				$res = new WP_Error( 'zdz_llm_poe', __( 'Poe could not answer.', 'zorderz' ), array( 'status' => $st, 'kind' => ZDZ_LLM_Wire::classify( $st ), 'billed' => false ) );
			}
			if ( is_wp_error( $res ) ) {
				$d = $res->get_error_data();
				$d = is_array( $d ) ? $d : array();
				if ( 'zdz_llm_guard' === $res->get_error_code() ) {
					$row['model'] = self::model_code( $model );
					return $this->finish_refusal( 'guard', $res->get_error_message(), $row, $usage, $spent, $tries, $fallback, $t0 );
				}
				if ( 404 === (int) ( $d['status'] ?? 0 ) && class_exists( 'ZDZ_Model_Registry' ) && method_exists( 'ZDZ_Model_Registry', 'mark_gone' ) ) {
					ZDZ_Model_Registry::mark_gone( (string) $model, (string) $r['provider'] );   // a real 404, from the provider
				}
				if ( null !== $rates && ! empty( $d['usage'] ) && is_array( $d['usage'] ) ) {
					$spent += self::cost( $rates, $d['usage'] + ZDZ_LLM_Wire::zero_usage(), 'openai' !== $r['provider'] );
					$usage  = self::add( $usage, $d['usage'] );
				} elseif ( ! empty( $d['billed'] ) ) {
					$spent += $fit[1];                     // it probably ran: counted at its worst case
				}
				$last = $res;
				continue;
			}
			// ---- an answer
			$u      = (array) $res['usage'] + ZDZ_LLM_Wire::zero_usage();
			$usage  = self::add( $usage, $u );
			$spent += null !== $res['usd'] ? (float) $res['usd'] : ( null !== $rates ? self::cost( $rates, $u, 'openai' !== $r['provider'] ) : 0.0 );
			$res    = self::from_handles_res( $res, (array) ( $in['handles'] ?? array() ) );   // rule 16: the full codes back first
			$out    = $this->answer( $res, $schema, $coder, $allowed_c, $allowed_e, (array) ( $in['company_words'] ?? array() ) );
			$row    = array_merge( $row, self::usage_row( $usage ), array(
				'provider'  => $prov->name(),
				'model'     => self::model_code( $model ),
				'usd_milli' => (int) ceil( $spent * 1000 ),
				'ms'        => (int) round( ( $this->now() - $t0 ) * 1000 ),
				'fallback'  => $fallback ? 1 : 0,
			) );
			if ( is_wp_error( $out ) ) {
				$row['outcome'] = substr( str_replace( 'zdz_llm_', '', $out->get_error_code() ), 0, 24 );
				$this->ledger( $row );
				// a failed answer still cost money; its error says how much
				return new WP_Error( $out->get_error_code(), $out->get_error_message(), (array) $out->get_error_data() + array( 'usd_milli' => $row['usd_milli'] ) );
			}
			$row['outcome'] = 'ok';
			$this->ledger( $row );
			return $out + array(
				'usage'        => $usage,
				'model'        => $model,
				'provider'     => $prov->name(),
				'fallback'     => $fallback,
				'coded_count'  => $row['coded_count'],
				'picture_sent' => $pictures,
				'usd'          => round( $spent, 4 ),
				'tries'        => $tries,
			);
		}

		// ---- no answer: said as one
		$row = array_merge( $row, self::usage_row( $usage ), array(
			'provider'  => $prov->name(),
			'model'     => self::model_code( $model ),
			'usd_milli' => (int) ceil( $spent * 1000 ),
			'ms'        => (int) round( ( $this->now() - $t0 ) * 1000 ),
			'fallback'  => $fallback ? 1 : 0,
		) );
		$d    = $last ? $last->get_error_data() : array();
		$kind = is_array( $d ) && isset( $d['kind'] ) ? preg_replace( '/[^a-z_]/', '', (string) $d['kind'] ) : 'other';
		$row['outcome'] = 'error_' . $kind;
		$this->ledger( $row );
		if ( ( 'anthropic' === $r['provider'] && $last && self::retryable( $last ) ) || ! $last ) {
			return new WP_Error( 'zdz_llm_unreachable', __( 'The Ai isn\'t reachable right now.', 'zorderz' ), array( 'status' => 0, 'kind' => $last ? $kind : 'other', 'usd_milli' => $row['usd_milli'] ) );
		}
		return new WP_Error( $last->get_error_code(), $last->get_error_message(), ( is_array( $last->get_error_data() ) ? $last->get_error_data() : array() ) + array( 'usd_milli' => $row['usd_milli'] ) );
	}

	private function finish_refusal( $code, $msg, array $row, array $usage, $spent, $tries, $fallback, $t0 ) {
		$row = array_merge( $row, self::usage_row( $usage ), array(
			'usd_milli' => (int) ceil( $spent * 1000 ),
			'ms'        => (int) round( ( $this->now() - $t0 ) * 1000 ),
			'fallback'  => $fallback ? 1 : 0,
		) );
		return $this->refuse( $code, $msg, $row );
	}

	/** The role's model unless the time left is under the role's line; then the quick model. null with no time at all. */
	protected function pick( array $r, $deadline ) {
		$left = $deadline - $this->now();
		if ( $left < self::MIN_SECONDS ) {
			return null;
		}
		$m = (string) $r['model'];
		if ( ! empty( $r['primary_only'] ) ) {
			return $m;   // rule 14: never the quick model, however little time is left
		}
		$models = self::models();
		if ( $m === $models['primary'] && '' !== $models['quick'] && $models['quick'] !== $m && $left < (int) $r['quick_line_s'] ) {
			return $models['quick'];
		}
		return $m;
	}

	/** After the primary fails: the fallback model, or the quick model when time is short. Never another provider. */
	protected function fallback_model( $failed, array $r, $deadline ) {
		if ( ! empty( $r['primary_only'] ) ) {
			return null;         // rule 14: no other model; the failure is the answer
		}
		$left = $deadline - $this->now();
		if ( $left < self::MIN_SECONDS ) {
			return null;
		}
		$models = self::models();
		if ( $left >= (int) $r['quick_line_s'] ) {
			$next = $models['fallback'] === $failed ? $models['quick'] : $models['fallback'];
			if ( self::gone( $next ) && $models['quick'] !== $failed ) {
				$next = $models['quick'];   // the fallback answered 404 within the hour: the quick model instead
			}
		} else {
			$next = $models['quick'] === $failed ? '' : $models['quick'];
		}
		if ( 'web' === ( $r['role'] ?? '' ) && '' !== $next && class_exists( 'ZDZ_Model_Registry' ) && ! ZDZ_Model_Registry::web_capable( $next ) ) {
			return null;   // a web question never falls to a model that cannot search
		}
		return ( '' === $next || $next === $failed || self::gone( $next ) ) ? null : $next;
	}

	/** A native-provider model that answered 404 within the hour (skipped as a fallback). */
	private static function gone( $model ) {
		return '' !== (string) $model && class_exists( 'ZDZ_Model_Registry' ) && method_exists( 'ZDZ_Model_Registry', 'is_gone' )
			&& ZDZ_Model_Registry::is_gone( (string) $model, 'anthropic' );
	}

	/**
	 * Would another model plausibly answer where this try failed? The busy, failing and unreachable kinds, and the
	 * statuses in ZDZ_Model_Registry::FAILOVER_STATUSES (a missing model, a refused body, a timeout). Never a key or
	 * permission problem, a refusal, the wrong account or the guard: no other model would do better.
	 */
	public static function fails_over( WP_Error $e ) {
		$d    = $e->get_error_data();
		$d    = is_array( $d ) ? $d : array();
		$kind = (string) ( $d['kind'] ?? '' );
		if ( in_array( $kind, array( 'auth', 'wrong_workspace', 'refused' ), true ) || 'zdz_llm_guard' === $e->get_error_code() ) {
			return false;
		}
		if ( in_array( $kind, array( 'overloaded', 'rate_limited', 'server', 'network' ), true ) ) {
			return true;
		}
		$st = isset( $d['status'] ) && is_numeric( $d['status'] ) ? (int) $d['status'] : 0;
		if ( $st <= 0 ) {
			return false;   // no status: one of the kinds above, or something no other model would answer
		}
		return class_exists( 'ZDZ_Model_Registry' ) && method_exists( 'ZDZ_Model_Registry', 'fails_over' )
			? ZDZ_Model_Registry::fails_over( $st )
			: in_array( $st, array( 0, 408, 429, 500, 502, 503, 504, 529 ), true );
	}

	/** Rule 4: medium, high for `reason` with 40 s or more left (or the row's own effort, where an admin set one). */
	protected function effort( $role, array $r, $left_s ) {
		if ( 'reason' === $role ) {
			return $left_s >= self::HIGH_EFFORT_S && 'high' === $r['effort'] ? 'high' : 'medium';
		}
		return 'high' === $r['effort'] && $left_s >= self::HIGH_EFFORT_S ? 'high' : 'medium';
	}

	private static function retryable( WP_Error $e ) {
		$d = $e->get_error_data();
		$k = is_array( $d ) ? ( $d['kind'] ?? '' ) : '';
		return in_array( $k, array( 'overloaded', 'rate_limited', 'server', 'network' ), true );
	}

	private static function add( array $a, array $b ) {
		foreach ( $a as $k => $v ) {
			$a[ $k ] = $v + (int) ( $b[ $k ] ?? 0 );
		}
		return $a;
	}

	private static function usage_row( array $u ) {
		return array( 'in_tokens' => $u['in'], 'cache_read' => $u['cache_read'], 'cache_write' => $u['cache_write_5m'] + $u['cache_write_1h'], 'out_tokens' => $u['out'], 'searches' => $u['searches'] );
	}

	/* ================================================================== rule 16: short handles */

	/** A handle's length: 4 characters and 1 check character. */
	const HANDLE_LEN = 5;

	const HANDLE_ABC = '0123456789abcdefghijklmnopqrstuvwxyz';

	/**
	 * The check character for a handle's 4 letters and digits. Weights 1-5 by position, sum modulo 37 (a prime), the
	 * check character chosen so the weighted sum of all 5 is 0 (its value 36 is written "_"). Changing ONE character
	 * changes the sum by w*d, never a multiple of 37; swapping two different neighbouring characters changes it by
	 * (w1-w2)(a-b), never a multiple of 37 either. So no one-character slip and no neighbour swap of a handle is ever
	 * another handle: a model's slipped copy can never open a different detail. (ISO 7064 MOD 37,36 misses some
	 * neighbour swaps, which is why it is not used.)
	 */
	public static function handle_check( $body ) {
		$body = (string) $body;
		if ( 4 !== strlen( $body ) ) {
			return null;
		}
		$sum = 0;
		for ( $i = 0; $i < 4; $i++ ) {
			$v = strpos( self::HANDLE_ABC, $body[ $i ] );
			if ( false === $v ) {
				return null;
			}
			$sum += ( $i + 1 ) * $v;
		}
		$c = ( ( 37 - ( $sum % 37 ) ) % 37 ) * 15 % 37;   // 15 = 5^-1 mod 37
		return 36 === $c ? '_' : self::HANDLE_ABC[ $c ];
	}

	/** A well-formed handle: 5 characters whose last is the check character of the first 4. */
	public static function handle_ok( $h ) {
		$h = (string) $h;
		return 5 === strlen( $h ) && 1 === preg_match( '/^[0-9a-z]{4}[0-9a-z_]$/', $h ) && self::handle_check( substr( $h, 0, 4 ) ) === $h[4];
	}

	/**
	 * Sealed code => its short handle. The prefix's (and the tier's) codes take handles made from the prefix itself (an
	 * HMAC keyed by its own bytes), so the same prefix gets the same handles on every call (rule 15's cache); every
	 * other code takes a fresh random one each call (a small number could be guessed onto another real detail). Only
	 * text the model reads is numbered: a PDF part's extracted text is not.
	 *
	 * @return array<string,string>
	 */
	public static function handles( $prefix, $system, array $messages, $tier = '' ) {
		$map  = array();
		$used = array();
		$key  = hash( 'sha256', 'zdz-llm-handle-v1|' . (string) $prefix, true );
		$make = function ( $code, $fixed ) use ( &$used, $key ) {
			for ( $n = 0; $n < 50; $n++ ) {
				$raw = $fixed ? hash_hmac( 'sha256', $n . '|' . $code, $key, true ) : random_bytes( 8 );
				$h   = '';
				foreach ( str_split( $raw ) as $b ) {
					$h .= self::HANDLE_ABC[ ord( $b ) % 36 ];
					if ( strlen( $h ) === self::HANDLE_LEN - 1 ) {
						break;
					}
				}
				$h .= self::handle_check( $h );
				if ( ! isset( $used[ $h ] ) ) {
					$used[ $h ] = true;
					return $h;
				}
			}
			return null;   // never in practice: the code is then sent as it is
		};
		// rule 17: the second block's codes take handles made the same fixed way, right after the prefix's
		$texts = array( array( (string) $prefix, true ), array( (string) $tier, true ), array( (string) $system, false ) );
		foreach ( $messages as $m ) {
			foreach ( (array) ( $m['content'] ?? array() ) as $p ) {
				if ( 'text' === ( $p['type'] ?? '' ) && isset( $p['text'] ) && '' !== $p['text'] ) {
					$texts[] = array( (string) $p['text'], false );
				}
			}
		}
		foreach ( $texts as $tf ) {
			foreach ( self::sealed_codes( $tf[0] ) as $code ) {
				if ( ! isset( $map[ $code ] ) && strlen( $code ) > 8 ) {
					$h = $make( $code, $tf[1] );
					if ( null !== $h ) {
						$map[ $code ] = $h;
					}
				}
			}
		}
		return $map;
	}

	/** Each sealed code in a text as its handle. */
	public static function to_handles( $text, array $map ) {
		if ( ! $map || '' === (string) $text ) {
			return (string) $text;
		}
		return (string) preg_replace_callback( '/' . self::OPEN . 'e:([A-Za-z0-9_\-]+)' . self::CLOSE . '/u', function ( $m ) use ( $map ) {
			return isset( $map[ $m[1] ] ) ? self::OPEN . 'e:' . $map[ $m[1] ] . self::CLOSE : $m[0];
		}, (string) $text );
	}

	/**
	 * Each handle in what came back as its sealed code. Only a well-formed handle this call gave out: a slip fails its
	 * check character and opens nothing; a handle the call never gave out is left as it is (from_reader() then drops
	 * it, as any code a reader made up).
	 */
	public static function from_handles( $text, array $map ) {
		if ( ! $map || ! is_string( $text ) || '' === $text ) {
			return $text;
		}
		$back = array_flip( $map );
		return (string) preg_replace_callback( '/' . self::OPEN . 'e:([0-9a-z]{4}[0-9a-z_])' . self::CLOSE . '/u', function ( $m ) use ( $back ) {
			return ( self::handle_ok( $m[1] ) && isset( $back[ $m[1] ] ) ) ? self::OPEN . 'e:' . $back[ $m[1] ] . self::CLOSE : $m[0];
		}, $text );
	}

	/** An answer's text and JSON (keys too) with the full codes back. */
	private static function from_handles_res( array $res, array $map ) {
		if ( ! $map ) {
			return $res;
		}
		$res['text'] = self::from_handles( (string) ( $res['text'] ?? '' ), $map );
		if ( isset( $res['json'] ) && is_array( $res['json'] ) ) {
			$res['json'] = self::from_handles_deep( $res['json'], $map );
		}
		return $res;
	}

	private static function from_handles_deep( $v, array $map ) {
		if ( is_array( $v ) ) {
			$out = array();
			foreach ( $v as $k => $x ) {
				$out[ is_string( $k ) ? self::from_handles( $k, $map ) : $k ] = self::from_handles_deep( $x, $map );
			}
			return $out;
		}
		return is_string( $v ) ? self::from_handles( $v, $map ) : $v;
	}

	private static function texts( array $messages ) {
		$out = array();
		foreach ( $messages as $m ) {
			foreach ( $m['content'] as $p ) {
				if ( isset( $p['text'] ) && '' !== $p['text'] ) {
					$out[] = $p['text'];
				}
			}
		}
		return $out;
	}

	/** A model id as a ledger code word (lower-case letters, digits, . _ : -; at most 32 characters). */
	public static function model_code( $m ) {
		$m = strtolower( trim( (string) $m ) );
		if ( '' === $m ) {
			return '';
		}
		$c = trim( (string) preg_replace( '/[^a-z0-9_.:\-]+/', '-', $m ), '-' );
		$c = substr( $c, 0, 32 );
		return '' === $c ? 'other' : $c;
	}

	/* ================================================================== the answer */

	/** Rule 5 back, rule 10, rule 12: the answer still coded, JSON checked, an empty answer said as a failure. */
	private function answer( array $res, $schema, $coder, array $allowed_c, array $allowed_e, array $company_words = array() ) {
		$raw = trim( (string) $res['text'] );
		if ( '' === $raw ) {
			return new WP_Error( 'zdz_llm_empty', __( 'The Ai gave no answer.', 'zorderz' ), array( 'status' => 0, 'kind' => 'empty' ) );
		}
		$json = null;
		if ( $schema ) {
			$j = is_array( $res['json'] ) ? $res['json'] : self::parse_json( $raw );
			if ( ! is_array( $j ) || ! self::matches( $j, $schema ) ) {
				return new WP_Error( 'zdz_llm_bad_json', __( 'The Ai answered in the wrong shape.', 'zorderz' ), array( 'status' => 0, 'kind' => 'bad_json' ) );
			}
			$json = self::from_reader_deep( $j, $coder, $allowed_c, $allowed_e, $company_words );
		}
		$cites = array();
		foreach ( (array) ( $res['citations'] ?? array() ) as $c ) {
			$cites[] = array( 'url' => (string) $c['url'], 'title' => self::defuse( (string) $c['title'] ) );
		}
		return array(
			'text'      => $coder->from_reader( $raw, $allowed_c, null, $allowed_e ),
			'json'      => $json,
			'citations' => $cites,
			'stop'      => preg_replace( '/[^a-z_]/', '', (string) ( $res['stop'] ?? '' ) ),   // end_turn, max_tokens, ...
		);
	}

	private static function from_reader_deep( $v, $coder, array $allowed_c, array $allowed_e, array $company_words = array() ) {
		if ( is_array( $v ) ) {
			$out = array();
			foreach ( $v as $k => $x ) {
				// keys too, or {"⟦c:9999⟧": 3} would reveal customer 9999 to a caller that decodes it
				$k2         = is_string( $k ) ? $coder->from_reader( $k, $allowed_c, null, $allowed_e ) : $k;
				$out[ $k2 ] = self::from_reader_deep( $x, $coder, $allowed_c, $allowed_e, $company_words );
			}
			return $out;
		}
		if ( is_string( $v ) && isset( $company_words[ $v ] ) && ! preg_match( '/^\p{Lu}/u', $v ) ) {
			return $v;   // an id-like word of the business text, exactly as it was sent ("widget-std"); a capitalised
		}                // word goes through the coder as any answer does
		return is_string( $v ) ? $coder->from_reader( $v, $allowed_c, null, $allowed_e ) : $v;
	}

	/** JSON in an answer: the whole of it, or a fenced or bracketed part (providers without structured outputs). */
	public static function parse_json( $text ) {
		$j = json_decode( $text, true );
		if ( is_array( $j ) ) {
			return $j;
		}
		if ( preg_match( '/```(?:json)?\s*(.*?)\s*```/s', $text, $m ) ) {
			$j = json_decode( $m[1], true );
			if ( is_array( $j ) ) {
				return $j;
			}
		}
		foreach ( array( '/\{.*\}/s', '/\[.*\]/s' ) as $re ) {
			if ( preg_match( $re, $text, $m ) ) {
				$j = json_decode( $m[0], true );
				if ( is_array( $j ) ) {
					return $j;
				}
			}
		}
		return null;
	}

	/** A small JSON Schema check: type, properties, required, additionalProperties false, enum, items. */
	public static function matches( $v, array $s ) {
		if ( isset( $s['enum'] ) && is_array( $s['enum'] ) && ! in_array( $v, $s['enum'], true ) ) {
			return false;
		}
		$types = isset( $s['type'] ) ? (array) $s['type'] : array();
		if ( $types ) {
			$ok = false;
			foreach ( $types as $t ) {
				$ok = $ok || self::is_type( $v, $t );
			}
			if ( ! $ok ) {
				return false;
			}
		}
		if ( is_array( $v ) && self::is_type( $v, 'object' ) ) {
			foreach ( (array) ( $s['required'] ?? array() ) as $k ) {
				if ( ! array_key_exists( $k, $v ) ) {
					return false;
				}
			}
			$props = (array) ( $s['properties'] ?? array() );
			foreach ( $v as $k => $x ) {
				if ( isset( $props[ $k ] ) ) {
					if ( ! self::matches( $x, (array) $props[ $k ] ) ) {
						return false;
					}
				} elseif ( isset( $s['additionalProperties'] ) && false === $s['additionalProperties'] ) {
					return false;
				}
			}
		}
		if ( is_array( $v ) && self::is_type( $v, 'array' ) && isset( $s['items'] ) && is_array( $s['items'] ) ) {
			foreach ( $v as $x ) {
				if ( ! self::matches( $x, $s['items'] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private static function is_type( $v, $t ) {
		switch ( $t ) {
			case 'object':
				return is_array( $v ) && ( ! $v || array_keys( $v ) !== range( 0, count( $v ) - 1 ) );
			case 'array':
				return is_array( $v ) && ( ! $v || array_keys( $v ) === range( 0, count( $v ) - 1 ) );
			case 'string':
				return is_string( $v );
			case 'integer':
				return is_int( $v );
			case 'number':
				return is_int( $v ) || is_float( $v );
			case 'boolean':
				return is_bool( $v );
			case 'null':
				return null === $v;
		}
		return true;
	}

	/* ================================================================== helpers */

	/**
	 * May these messages travel with web search on? Only a short, text-only question: at most [system, user], the user
	 * message last, every content a string, WEB_SAFE_MAX_CHARS in all, and no platform data block however small.
	 * Anything more -- history, a data block, an image, a file -- is business data, and a web-searching model turns
	 * what it reads into search queries nobody here can see. Structural on purpose: it does not depend on recognising
	 * the data. The Poe gateway's own copy of the rule is used when it has one.
	 */
	public static function web_safe( array $messages ) {
		if ( class_exists( 'ZDZ_Core_Poe' ) && method_exists( 'ZDZ_Core_Poe', 'web_safe' ) ) {
			return (bool) ZDZ_Core_Poe::web_safe( $messages );
		}
		if ( ! $messages || count( $messages ) > 2 ) {
			return false;
		}
		$chars = 0;
		$roles = array();
		foreach ( array_values( $messages ) as $m ) {
			$role    = is_array( $m ) ? (string) ( $m['role'] ?? '' ) : '';
			$content = is_array( $m ) ? ( $m['content'] ?? null ) : null;
			if ( ! in_array( $role, array( 'system', 'user' ), true ) || ! is_string( $content ) ) {
				return false;
			}
			foreach ( array( 'DATA-START', 'DATA AVAILABLE', '=== INVOICES' ) as $mk ) {
				if ( false !== strpos( $content, $mk ) ) {
					return false;
				}
			}
			$roles[] = $role;
			$chars  += function_exists( 'mb_strlen' ) ? mb_strlen( $content ) : strlen( $content );
		}
		if ( 'user' !== end( $roles ) || ( 2 === count( $roles ) && 'system' !== $roles[0] ) ) {
			return false;
		}
		return $chars <= self::WEB_SAFE_MAX_CHARS;
	}

	/** The shared device, by its capability, its role, or the hierarchy's word for it. */
	public static function is_kiosk( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return true;
		}
		$u = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		return $u && in_array( 'zdz_general', (array) $u->roles, true );
	}

	/** One slot per call in progress: was its ledger row written? (a hold is released only then) */
	protected $written = array();

	/** One ledger row, then the month's alerts. */
	protected function ledger( array $row ) {
		$ok = (bool) ZDZ_LLM_Ledger::write( $row );
		if ( $this->written ) {
			$this->written[ count( $this->written ) - 1 ] = $ok;
		}
		if ( ! $ok ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( 'ZDZ_Core_LLM: a ledger row could not be written (' . preg_replace( '/[^a-z0-9_]/', '', (string) ( $row['outcome'] ?? '' ) ) . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return;
		}
		if ( ! empty( $row['usd_milli'] ) ) {
			self::maybe_alert();
		}
	}

	/** The bell, to the admin tier, once a month at each of 50% and 80% of the site's monthly line. */
	public static function maybe_alert() {
		$b = self::budgets();
		if ( ! $b['set'] || $b['app'] <= 0 || ! class_exists( 'ZDZ_Alert_Router' ) || ! method_exists( 'ZDZ_Alert_Router', 'send' ) ) {
			return;
		}
		$mtd = ZDZ_LLM_Ledger::month_to_date();
		if ( null === $mtd ) {
			return;
		}
		$hit = self::alert_mark( (float) $mtd['app'], (float) $b['app'], self::alerts_sent() );
		if ( null === $hit ) {
			return;
		}
		$sent = self::alerts_sent();
		foreach ( self::ALERT_AT as $pc ) {
			if ( $pc <= $hit && ! in_array( $pc, $sent['at'], true ) ) {
				$sent['at'][] = $pc;   // one notice for the highest mark crossed
			}
		}
		update_option( self::ALERTS_OPTION, $sent, false );
		$title = sprintf( __( 'The site\'s Ai spend reached %1$d%% of this month\'s %2$s', 'zorderz' ), $hit, '$' . number_format( $b['app'], 2 ) );
		$msg   = sprintf(
			__( '%1$s of %2$s so far this month. Everything in the Ai ledger: %3$s of the %4$s ceiling. Zorderz → Ai usage has the detail.', 'zorderz' ),
			'$' . number_format( $mtd['app'], 2 ),
			'$' . number_format( $b['app'], 2 ),
			'$' . number_format( $mtd['all'], 2 ),
			'$' . number_format( (float) $b['ceiling'], 2 )
		);
		$url = function_exists( 'admin_url' ) && class_exists( 'ZDZ_LLM_Admin' ) ? admin_url( 'admin.php?page=' . ZDZ_LLM_Admin::USAGE_PAGE ) : '';
		foreach ( self::admin_tier() as $uid ) {
			ZDZ_Alert_Router::send( self::ALERT_TYPE, $uid, $title, $msg, array( 'plugin' => 'zorderz', 'url' => $url, 'sender_id' => 0 ) );
		}
	}

	/** This month's record of the marks already announced. */
	private static function alerts_sent() {
		$month = gmdate( 'Y-m' );
		$sent  = function_exists( 'get_option' ) ? get_option( self::ALERTS_OPTION, array() ) : array();
		return is_array( $sent ) && isset( $sent['month'] ) && $month === $sent['month'] && is_array( $sent['at'] ?? null ) ? $sent : array( 'month' => $month, 'at' => array() );
	}

	/** The highest mark (per cent) the spend has crossed that has not been announced this month, or null. */
	public static function alert_mark( $spent, $line, array $sent ) {
		if ( $line <= 0 ) {
			return null;
		}
		$hit = null;
		foreach ( self::ALERT_AT as $pc ) {
			if ( $spent >= $line * $pc / 100 && ! in_array( $pc, (array) ( $sent['at'] ?? array() ), true ) ) {
				$hit = $pc;
			}
		}
		return $hit;
	}

	/** The admin tier's user ids (never the shared device). */
	public static function admin_tier() {
		$out = array();
		if ( ! function_exists( 'get_users' ) ) {
			return $out;
		}
		foreach ( get_users( array( 'fields' => array( 'ID' ), 'number' => 300 ) ) as $u ) {
			$id    = (int) $u->ID;
			$admin = class_exists( 'ZDZ_Hierarchy' ) ? ZDZ_Hierarchy::is_admin( $id ) : ( function_exists( 'user_can' ) && user_can( $id, 'manage_options' ) );
			if ( $admin && ! self::is_kiosk( $id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
