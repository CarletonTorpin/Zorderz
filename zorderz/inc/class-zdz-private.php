<?php
/**
 * ZDZ_Private -- the customer-detail coder.
 *
 * One rule for every app that sends words to be read by a model, or keeps them in its own tables: customer details are
 * coded before they leave the code that holds them.
 *
 *   ⟦c:<id>⟧      a customer the sales ledger knows (the on-site copy of the billing system's invoices and estimates).
 *   ⟦e:<sealed>⟧  anything else private: a customer name only Jobs, Estimates, Commissions or the inbox know
 *                 (ZDZ_Ask_Context::customer_names()), a surname in a work phrase ("the Example job"), a phone number,
 *                 an e-mail, a street address. Sealed with libsodium (secretbox) under a key made from this site's salts
 *                 and the caller's PURPOSE, so the assistant's codes and the Hours app's codes are not interchangeable.
 *   [gate code sealed]  a gate, lockbox, alarm, door or keypad code (ZDZ_Sealed::cut()). NEVER an ⟦e⟧: nothing that
 *                 leaves the site for a model may carry a code in any form. It runs first, so no later rule (a phone
 *                 number, an address) can turn a code into an ⟦e⟧ either.
 *
 * Each caller asks for its own coder:  ZDZ_Private::for( 'assistant' ), ZDZ_Private::for( 'hours', $opts ) …
 *
 *   for( 'hours' )   the Hours app's coder for its own stored notes: its own key derivation ("zhr-private-v1|" and the
 *                    auth + secure_auth salts), its own name index (transient zhr_name_index_v1, its own team list),
 *                    a random nonce, and codes sealed as ⟦e⟧ inside its stored notes so every note it ever stored still
 *                    decodes. No first-name rule and no place list. Every other purpose cuts codes.
 *   for( <other> )   key "zdz-private-v1|<purpose>|" + the salts; codes cut by ZDZ_Sealed (and sealed into the vault
 *                    when the caller passes encode() a scope); a shared name index (transient zdz_private_names_v1).
 *
 * Decoding never calls the billing system, the CRM or anything outside the site. decode( $text, $show ) shows plain words
 * only when the caller says the reader is the writer or the admin tier ($show true); otherwise "a customer" /
 * "[private]". from_reader() keeps a code a model wrote back only when it was in what it was given, so
 * "list ⟦c:1⟧ … ⟦c:9999⟧" reveals no one.
 *
 *   company text   encode( …, array( 'company' => true ) ): words the business wrote (a catalog, a prompt). The two
 *                  rules that fire on ONE word (a known surname with a capital, a product id) are off in it; a full
 *                  customer name, an e-mail, a phone, a street and a gate code are still coded.
 *   one code each  outside Hours, a sealed detail gets the same ⟦e⟧ every time until new_request() (the nonce is an HMAC
 *                  of the words under a nonce key of the purpose's own, salted per request). The model router calls
 *                  new_request() once per call, so a model sees one person once within a call and its retries, and the
 *                  next call (in a long cron run too) gets other codes. Every code made before still opens (the nonce
 *                  travels in the code). fixed_salt() pins the salt for a cached prompt prefix.
 *   first names    a customer's first name (the first word of a two- or three-word person's name the site knows) is
 *                  coded in a contact phrase ("spoke to Sam", "per sam", "left vm for sam", "Sam called"); never a
 *                  team member's first name, an everyday word (PLAIN_FIRST: Will, Bill, Mark …) or a word a plugin
 *                  names (filter zdz_private_not_first_names: a catalog's own words); never for Hours. In company text
 *                  only beside a known surname ("Sam Example").
 *   never coded    the people who work here (team), and the business's own names from the Business Profile (legal,
 *                  trading, short and former names, short code), whole or word by word.
 *   places         a place of two or more words written whole is never read as a name. Core ships no places: the
 *                  business's region fills the list (option zdz_private_places, filter zdz_private_places).
 *   plain words    words the business uses that are never a name on their own (its product words) join COMMON
 *                  (option zdz_private_plain_words, filter zdz_private_plain_words).
 *
 * Not caught: a first name outside a contact phrase (too many are plain words: Will, Grant, Bill, Mark), a name in no
 * business record and in no work phrase, and anything written on a picture.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZDZ_Sealed' ) ) {
	require_once __DIR__ . '/class-zdz-sealed.php'; // codes are always cut; never fall back to an e-code
}

class ZDZ_Private {

	const OPEN  = '⟦';
	const CLOSE = '⟧';

	/** The day and month words of COMMON: never coded just because a work word follows. */
	const CALENDAR = array( 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/** A title before a word makes it a name, a common word too ("Mrs Thursday", "Mr Price"). */
	const TITLES = array( 'mr', 'mrs', 'ms', 'miss', 'dr' );

	/**
	 * Words that are surnames but also plain English; alone they are never taken for a customer. "business" and
	 * "businesses" ("Tell me everything about the business …"), and every month and weekday name in full (with a
	 * customer surnamed Thursday, "worked Thursday 7 to 3" must keep its day). A business adds its own product words
	 * with plain_words().
	 */
	const COMMON = array( 'business', 'businesses', 'wood', 'woods', 'price', 'young', 'king', 'rose', 'may', 'white', 'brown', 'green', 'black', 'gray', 'grey', 'hill', 'hills', 'lane', 'park', 'parks', 'street', 'stone', 'long', 'short', 'little', 'bell', 'bird', 'fox', 'wolf', 'cook', 'baker', 'mason', 'carpenter', 'smith', 'miller', 'hunter', 'fisher', 'turner', 'porter', 'cole', 'west', 'east', 'north', 'south', 'case', 'chase', 'page', 'grant', 'hope', 'joy', 'love', 'rich', 'sharp', 'swift', 'strong', 'best', 'good', 'gold', 'silver', 'day', 'night', 'week', 'march', 'april', 'june', 'august', 'january', 'february', 'july', 'september', 'october', 'november', 'december', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'shop', 'job', 'house', 'home', 'door', 'doors', 'screen', 'screens', 'window', 'windows', 'install', 'service', 'office', 'yard', 'garage', 'patio', 'side', 'front', 'back', 'top', 'mesh', 'frame', 'vent', 'vents', 'roll', 'cut', 'drive', 'route', 'walk', 'truck', 'van', 'lunch', 'break', 'today', 'yesterday', 'morning', 'afternoon', 'evening', 'noon', 'midnight', 'hour', 'hours', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun', 'and', 'the', 'for', 'with', 'from', 'over', 'then', 'also', 'more', 'less', 'done', 'new', 'old' );

	/**
	 * Places of two or more words, where the business works and nearby. Written as the whole place, their words are a
	 * place and never a name: "Lake Example" is not "Lake" and a customer called Example. A full customer name the lists
	 * know is still coded whole first, and a word alone ("the Example job", "Example called") is still read as before.
	 * One-word towns do not belong here: a town's name can be a customer's surname, so it keeps the surname rules.
	 *
	 * Core ships none: the list is the business's region (places()), from option PLACES_OPT and filter
	 * zdz_private_places.
	 */
	const PLACES = array();

	/** Option holding the business's multi-word places (a list of strings). */
	const PLACES_OPT = 'zdz_private_places';

	/** Option holding the business's own plain words (product words that are never a name on their own). */
	const PLAIN_WORDS_OPT = 'zdz_private_plain_words';

	/** Words before or after a surname that make it a customer ("the Example job", "at Example's", "for the Examples"). */
	const BEFORE = array( 'the', 'at', 'for', 'with', 'from', 'to', 'over', 'customer', 'client', 'mr', 'mrs', 'ms', 'miss', 'dr' );
	/** Words that make a name a business, not a person: such a name is still coded whole, but gives no "surname". */
	const BUSINESS = array( 'llc', 'inc', 'corp', 'corporation', 'co', 'company', 'companies', 'business', 'businesses', 'service', 'services', 'construction', 'contractors', 'contracting', 'builders', 'building', 'hoa', 'association', 'assn', 'glass', 'depot', 'group', 'homes', 'realty', 'real', 'estate', 'properties', 'property', 'management', 'church', 'school', 'apartments', 'apartment', 'condo', 'condos', 'village', 'estates', 'club', 'community', 'center', 'centre', 'screens', 'screen', 'windows', 'doors', 'supply', 'supplies', 'solutions', 'design', 'designs', 'studio', 'shop', 'store', 'market', 'hotel', 'inn', 'resort', 'ranch', 'farms', 'farm', 'trust', 'family', 'partners', 'enterprises', 'industries', 'systems', 'pool', 'pools', 'landscaping', 'roofing', 'painting', 'plumbing', 'electric', 'hvac', 'the', 'of', 'and' );

	/** Words a first name follows in a contact phrase. */
	const CONTACT = array( 'to', 'with', 'w', 'per', 'from', 'for', 'call', 'called', 'spoke', 'sp', 'talked', 'told', 'asked', 'text', 'texted', 'emailed', 'mr', 'mrs', 'ms', 'dr', 'vm', 'lvm', 'voicemail', 'msg', 'message', 'messaged' );
	/**
	 * …of them, the plain little words ("to", "for", "per", "w"): a lower-case name after them needs a contact verb
	 * before ("sp to sam"); after "per" also when it ends the clause ("pd per sam", "per sam, will pay"). "W" counts as
	 * "with" only written "w/" or after a contact verb ("Install W Maple St" is a street).
	 */
	const CONTACT_WEAK = array( 'to', 'with', 'from', 'for', 'per', 'w' );
	/** Words after a first name that opens a note ("Sam called", "Sam says"). */
	const CONTACT_NEXT = array( 'called', 'calls', 'says', 'said', 'wants', 'will', 'paid', 'texted', 'emailed' );
	/** …of them, the ones that make a name a name anywhere in a sentence ("then Sam says", "then sam called back"). */
	const CONTACT_VERB = array( 'called', 'calls', 'says', 'said', 'texted', 'emailed' );
	/** …and of those, the ones only a person does: a lower-case name before them counts too ("the tag says" stays). */
	const CONTACT_DID = array( 'called', 'texted', 'emailed' );
	/** First names that are everyday words in invoices and notes: never taken for a first name alone. */
	const PLAIN_FIRST = array( 'will', 'bill', 'mark', 'max', 'art', 'rob', 'sue', 'don', 'jack', 'ray', 'chuck', 'guy', 'gene', 'herb', 'dawn', 'eve', 'grace', 'faith', 'sky', 'summer', 'ivy', 'iris', 'penny', 'dean', 'wade', 'drew', 'cliff', 'glen', 'dale', 'rod', 'pierce', 'lance', 'miles', 'standard', 'premier', 'royal', 'major', 'sterling', 'pearl', 'ruby', 'amber', 'jade', 'crystal', 'sandy', 'rusty', 'bud', 'buck', 'sonny', 'king', 'prince', 'earl', 'duke', 'baron', 'carol', 'joy', 'hope', 'patience', 'temperance', 'honor', 'justice', 'august', 'june', 'april', 'may', 'jan', 'les', 'van', 'ted' );

	const AFTER  = array( 'job', 'jobs', 'house', 'home', 'place', 'residence', 'property', 'install', 'installation', 'project', 'estimate', 'invoice', 'remeasure', 'measure', 'repair', 'family' );

	/** The purpose whose key, index and code handling are the Hours app's own (its stored notes). */
	const LEGACY_HOURS = 'hours';

	/** The sales ledger's on-site tables (without the prefix), invoices first. Filter: zdz_private_ledger_tables. */
	const LEDGER_TABLES = array( 'zana_ledger_invoices', 'zana_ledger_estimates' );

	private static $coders = array();

	/** @var array|null this request's word lists: ['common' => set, 'places' => by first word] */
	private static $lists = null;

	private $purpose;
	private $opts;
	private $memo_name = array();
	private $memo_idx  = null;
	private $salt      = null;   // this request's salt for the sealing nonce
	private $memo_plain = null;  // words a plugin says are never a first name (zdz_private_not_first_names)
	private $memo_var   = array( '', array() );   // [ the list's signature, its names' other orders ]

	/**
	 * The coder for $purpose (a short lower-case word: 'assistant', 'hours', …). $opts, read on the first call:
	 *   team         callable → the names of the people who work here (never coded). Default: the site's people
	 *                who can use the app, the shared account left out.
	 *   index        the transient the name index is kept in.
	 *   table_exists callable( $table ) → bool, for the ledger tables.
	 *   log          the words an error_log line starts with.
	 * These configure the purpose, so the first call's win. What belongs to one record (the ZDZ_Sealed scope a code is
	 * sealed under) is passed to encode() itself, never here.
	 */
	public static function for( $purpose, array $opts = array() ) {
		$p = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $purpose ) );
		if ( '' === $p ) {
			$p = 'default';
		}
		if ( ! isset( self::$coders[ $p ] ) ) {
			self::$coders[ $p ] = new self( $p, $opts );
		}
		return self::$coders[ $p ];
	}

	private function __construct( $purpose, array $opts ) {
		$this->purpose = $purpose;
		$this->opts    = $opts + array(
			'team'         => null,
			'index'        => self::LEGACY_HOURS === $purpose ? 'zhr_name_index_v1' : 'zdz_private_names_v1',
			'table_exists' => null,
			'log'          => self::LEGACY_HOURS === $purpose ? 'Hours' : 'Zorderz',
		);
	}

	public function purpose() {
		return $this->purpose;
	}

	/** True when codes become "[gate code sealed]"; false only for the Hours app's own stored notes (see the class comment). */
	public function cuts_codes() {
		return self::LEGACY_HOURS !== $this->purpose;
	}

	/* ------------------------------------------------------------------ the word lists a business adds to */

	/** Normalised words of a list (option, filter or pack value): strings only, each through norm(). Pure. */
	public static function clean_list( $list ) {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $w ) {
			if ( is_string( $w ) ) {
				$k = self::norm( $w );
				if ( '' !== $k ) {
					$out[ $k ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * This request's lists: COMMON with the business's plain words, and the places by their first word. Read once a
	 * request (new_request(), forget() and flush_lists() read them again).
	 */
	private static function lists() {
		if ( is_array( self::$lists ) ) {
			return self::$lists;
		}
		$plain  = function_exists( 'get_option' ) ? get_option( self::PLAIN_WORDS_OPT, array() ) : array();
		$plain  = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_private_plain_words', is_array( $plain ) ? $plain : array() ) : $plain;
		$places = function_exists( 'get_option' ) ? get_option( self::PLACES_OPT, array() ) : array();
		$places = array_merge( self::PLACES, is_array( $places ) ? $places : array() );
		$places = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_private_places', $places ) : $places;
		$plain  = self::clean_list( $plain );
		$places = self::clean_list( $places );
		$common = array_fill_keys( self::COMMON, true );
		foreach ( $plain as $w ) {
			if ( false === strpos( $w, ' ' ) ) {   // one word each: a phrase is never a "plain word"
				$common[ $w ] = true;
			}
		}
		$by_first = array();
		foreach ( $places as $pl ) {
			$w = explode( ' ', $pl );
			if ( count( $w ) >= 2 ) {               // one-word towns keep the surname rules (see PLACES)
				$by_first[ $w[0] ][] = $w;
			}
		}
		return self::$lists = array( 'common' => $common, 'places' => $by_first );
	}

	/** The words this site treats as plain English (COMMON and the business's plain words): [ word => true ]. */
	public static function common_words() {
		return self::lists()['common'];
	}

	/** The multi-word places this site never reads as a name (lower case, normalised). Empty unless the business sets them. */
	public static function places() {
		$out = array();
		foreach ( self::lists()['places'] as $group ) {
			foreach ( $group as $w ) {
				$out[] = implode( ' ', $w );
			}
		}
		return $out;
	}

	/** Read the word lists again (after the options change; tests). */
	public static function flush_lists() {
		self::$lists = null;
	}

	private static function is_common( $w ) {
		return isset( self::lists()['common'][ (string) $w ] );
	}

	/**
	 * The business's own names, never coded: legal, trading, short and former names and the short code from the
	 * Business Profile, plus filter zdz_private_own_names. Normalised, whole names (each word is added by index()).
	 */
	public static function own_names() {
		$n = array();
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			foreach ( array( 'identity.legal_name', 'identity.trading_name', 'identity.short_name', 'identity.short_code' ) as $path ) {
				$n[] = (string) ZDZ_Business_Profile::get( $path, '' );
			}
			$former = ZDZ_Business_Profile::get( 'identity.former_names', array() );
			foreach ( is_array( $former ) ? $former : array( $former ) as $f ) {
				$n[] = is_scalar( $f ) ? (string) $f : '';
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$n = array_merge( $n, (array) apply_filters( 'zdz_private_own_names', array() ) );
		}
		return self::clean_list( $n );
	}

	/* ------------------------------------------------------------------ sealing */

	private function key() {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'zhr' );
		if ( self::LEGACY_HOURS === $this->purpose ) {
			return hash( 'sha256', 'zhr-private-v1|' . $salt, true ); // the Hours app's own key: its stored codes keep opening
		}
		return hash( 'sha256', 'zdz-private-v1|' . $this->purpose . '|' . $salt, true );
	}

	public static function can_seal() {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	private static function b64( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	private static function unb64( $s ) {
		$s = strtr( (string) $s, '-_', '+/' );
		$p = strlen( $s ) % 4;
		return base64_decode( $p ? $s . str_repeat( '=', 4 - $p ) : $s, true );
	}

	/** The one place random bytes are drawn. */
	private static function random( $n ) {
		return random_bytes( $n );
	}

	/**
	 * The nonce. Hours: random. Every other purpose: an HMAC of the words under this purpose's key and this request's
	 * salt, so the same words seal to the same code within a request and to another code in the next one. A nonce made
	 * from the message never repeats for two different messages (the HMAC differs).
	 */
	private function nonce( $plain ) {
		if ( self::LEGACY_HOURS === $this->purpose ) {
			return self::random( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		}
		if ( null === $this->salt ) {
			$this->salt = self::random( 32 );
		}
		// a key of its own for the nonce, derived from the purpose's key: one key, one job
		$nk = hash( 'sha256', 'zdz-private-nonce-v1|' . $this->key(), true );
		return substr( hash_hmac( 'sha256', $this->salt . "\0" . (string) $plain, $nk, true ), 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	}

	/**
	 * Sealed codes made from now on come from a salt fixed by $seed, so the same words under the same seed seal to the
	 * same code in every request. The model router seeds it with a cached prefix's own text: the prefix is the same
	 * business text on every call, so the same codes reveal nothing the text does not, and a fresh code per call would
	 * stop a provider's prompt cache from ever being read. Returns the salt it replaced, for restore_salt(). Never for
	 * Hours (its nonce is random).
	 */
	public function fixed_salt( $seed ) {
		$prev       = $this->salt;
		$this->salt = hash_hmac( 'sha256', 'zdz-private-fixed-salt-v1|' . (string) $seed, hash( 'sha256', 'zdz-private-fixed-v1|' . $this->key(), true ), true );
		return $prev;
	}

	/** Put back the salt fixed_salt() replaced (null: the next code draws a new one). */
	public function restore_salt( $prev ) {
		$this->salt = ( is_string( $prev ) && 32 === strlen( $prev ) ) ? $prev : null;
	}

	/** A new request starts: sealed codes made from now on differ from those made before (tests, long runs). */
	public function new_request() {
		$this->salt       = null;
		$this->memo_plain = null;   // a plugin's words read again (a catalog edited during a long run)
		self::$lists      = null;   // and the business's word lists
	}

	public function seal( $plain ) {
		if ( ! self::can_seal() ) {
			return self::OPEN . 'e:' . self::CLOSE; // no libsodium: drop the detail rather than store it
		}
		$nonce = $this->nonce( $plain );
		return self::OPEN . 'e:' . self::b64( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, $this->key() ) ) . self::CLOSE;
	}

	/** The sealed text, or null when it cannot be opened (another site's key, another purpose's key, a forged code). */
	public function open( $code ) {
		if ( ! self::can_seal() ) {
			return null;
		}
		$bin = self::unb64( $code );
		if ( false === $bin || strlen( $bin ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		$out = sodium_crypto_secretbox_open( substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $this->key() );
		return false === $out ? null : $out;
	}

	/* ------------------------------------------------------------------ the names this site knows */

	public static function norm( $s ) {
		if ( class_exists( 'ZDZ_Ask_Context' ) && method_exists( 'ZDZ_Ask_Context', 'norm' ) ) {
			return ZDZ_Ask_Context::norm( (string) $s );
		}
		$s = strtolower( function_exists( 'remove_accents' ) ? remove_accents( (string) $s ) : (string) $s );
		$s = preg_replace( '/[^a-z0-9 ]+/', ' ', $s );
		return trim( preg_replace( '/\s+/', ' ', $s ) );
	}

	private function table_exists( $table ) {
		if ( is_callable( $this->opts['table_exists'] ) ) {
			return (bool) call_user_func( $this->opts['table_exists'], $table );
		}
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === (string) $table;
	}

	/** Ledger tables that exist: invoices first (the bigger copy), then estimates. */
	private function ledger_tables() {
		global $wpdb;
		$out    = array();
		$tables = function_exists( 'apply_filters' ) ? (array) apply_filters( 'zdz_private_ledger_tables', self::LEDGER_TABLES ) : self::LEDGER_TABLES;
		foreach ( $tables as $t ) {
			$t = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $t ) );
			if ( '' !== $t && is_object( $wpdb ) && $this->table_exists( $wpdb->prefix . $t ) ) {
				$out[] = $wpdb->prefix . $t;
			}
		}
		return $out;
	}

	/** The people who work here (their names are never coded). */
	private function team_names() {
		if ( is_callable( $this->opts['team'] ) ) {
			return (array) call_user_func( $this->opts['team'] );
		}
		$out = array();
		if ( function_exists( 'get_users' ) ) {
			foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ), 'orderby' => 'display_name', 'number' => 300 ) ) as $u ) {
				if ( class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( (int) $u->ID ) ) {
					continue;
				}
				$out[] = (string) $u->display_name;
			}
		}
		return $out;
	}

	/**
	 * The names to look for, kept 12 hours (server side only):
	 *   ledger  norm(name) => client id   (sales ledger rows with a client id)
	 *   other   norm(name) => true        (the business's other records: Jobs, Projects, Estimates, Commissions, inbox)
	 *   surname norm(word) => true        (the last word of every multi-word name, not a common word)
	 *   team    norm(name) => true        (people who work here, and the business's own names: never encoded)
	 *   first   norm(word) => true        (the first word of a person's name, not a common word, not a team member's;
	 *                                      coded only in a contact phrase)
	 */
	public function index() {
		if ( null !== $this->memo_idx ) {
			return $this->memo_idx;
		}
		$sig = $this->ledger_signature();
		$c   = get_transient( $this->opts['index'] );
		if ( is_array( $c ) && isset( $c['ledger'] ) && ( $c['sig'] ?? '' ) === $sig ) {
			if ( ! isset( $c['first'] ) && self::LEGACY_HOURS !== $this->purpose ) {
				$c['first'] = self::first_names( $c );   // a list kept without first names: add them from what it holds
				set_transient( $this->opts['index'], $c, ( $c['ledger'] || $c['other'] ) ? 12 * HOUR_IN_SECONDS : 300 );
			}
			if ( ! empty( $c['surname'] ) && is_array( $c['surname'] ) ) {
				// a list kept by an older build may name a word BUSINESS now holds ("business"), which no build of
				// this one would; it goes at once rather than when the list is next built
				$c['surname'] = array_diff_key( $c['surname'], array_flip( self::BUSINESS ) );
			}
			$this->memo_idx = $c;
			return $c;
		}
		global $wpdb;
		$idx = array( 'ledger' => array(), 'other' => array(), 'surname' => array(), 'team' => array() );
		foreach ( $this->ledger_tables() as $t ) {
			// every client, deleted ones too: an old note can name one
			$rows = $wpdb->get_results( "SELECT customer_id, customer_name FROM {$t} WHERE customer_id > 0 AND customer_name <> '' ORDER BY customer_id DESC LIMIT 60000", ARRAY_A ); // phpcs:ignore
			if ( null === $rows || '' !== (string) $wpdb->last_error ) {
				error_log( $this->opts['log'] . ': the customer list could not be read from ' . $t . ' (' . $wpdb->last_error . '); names are found only by their phrases' ); // phpcs:ignore
				$rows = array();
			}
			foreach ( $rows as $r ) {
				$k = self::norm( $r['customer_name'] );
				if ( strlen( $k ) >= 5 && false !== strpos( $k, ' ' ) && ! isset( $idx['ledger'][ $k ] ) ) {
					$idx['ledger'][ $k ] = (int) $r['customer_id'];
				}
			}
		}
		if ( class_exists( 'ZDZ_Ask_Context' ) && method_exists( 'ZDZ_Ask_Context', 'customer_names' ) ) {
			foreach ( (array) ZDZ_Ask_Context::customer_names() as $k ) {
				$k = self::norm( $k );
				if ( '' !== $k && ! isset( $idx['ledger'][ $k ] ) ) {
					$idx['other'][ $k ] = true;
				}
			}
		}
		foreach ( array_merge( array_keys( $idx['ledger'] ), array_keys( $idx['other'] ) ) as $k ) {
			$w = explode( ' ', $k );
			$s = end( $w );
			// a person's name: two or three words, none of them a business word ("Example Construction" gives nothing)
			if ( count( $w ) >= 2 && count( $w ) <= 3 && strlen( $s ) >= 3 && ! ctype_digit( $s ) && ! array_intersect( $w, self::BUSINESS ) ) {
				$idx['surname'][ $s ] = true;
			}
		}
		$team = $this->team_names();
		if ( self::LEGACY_HOURS !== $this->purpose ) {
			$team = array_merge( $team, self::own_names() );   // the business's own names are never a customer
		}
		foreach ( $team as $name ) {
			$k = self::norm( $name );
			if ( '' !== $k ) {
				$idx['team'][ $k ] = true;
				$tw = explode( ' ', $k );
				foreach ( $tw as $w ) {
					$idx['team'][ $w ] = true;
				}
				if ( count( $tw ) >= 3 ) {   // "Robin Ann Casey" is written "Robin Casey"
					$idx['team'][ $tw[0] . ' ' . end( $tw ) ] = true;
				}
			}
		}
		if ( self::LEGACY_HOURS !== $this->purpose ) {
			$idx['first'] = self::first_names( $idx );   // Hours' list keeps its own keys
		}
		$idx['sig'] = $sig;
		set_transient( $this->opts['index'], $idx, ( $idx['ledger'] || $idx['other'] ) ? 12 * HOUR_IN_SECONDS : 300 );
		$this->memo_idx = $idx;
		return $idx;
	}

	/**
	 * The first word of every person's name the list holds (two or three words, no business word), at least three
	 * letters, not a common word or an everyday one (PLAIN_FIRST), not a word of a team member's name.
	 */
	private static function first_names( array $idx ) {
		$out = array();
		foreach ( array_merge( array_keys( (array) ( $idx['ledger'] ?? array() ) ), array_keys( (array) ( $idx['other'] ?? array() ) ) ) as $k ) {
			$w = explode( ' ', (string) $k );
			while ( count( $w ) > 2 && in_array( $w[0], array( 'mr', 'mrs', 'ms', 'miss', 'dr' ), true ) ) {
				array_shift( $w );                       // "Mrs Sam Example": the first name is Sam
			}
			$f = $w[0];
			// a person's name, as for surnames: two or three words, no business word
			if ( count( $w ) >= 2 && count( $w ) <= 3 && strlen( $f ) >= 3 && ! ctype_digit( $f ) && ! array_intersect( $w, self::BUSINESS ) && ! self::is_common( $f ) && ! in_array( $f, self::PLAIN_FIRST, true ) && ! isset( $idx['team'][ $f ] ) ) {
				$out[ $f ] = true;
			}
		}
		return $out;
	}

	/**
	 * How big the ledger is now: a client added to the billing system this morning rebuilds the list at once. The
	 * business's own names are part of it too, so a renamed business is set aside at once.
	 */
	private function ledger_signature() {
		global $wpdb;
		$sig = '';
		foreach ( $this->ledger_tables() as $t ) {
			$sig .= (string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(customer_id), 0)) FROM {$t}" ) . '|'; // phpcs:ignore
		}
		if ( self::LEGACY_HOURS !== $this->purpose ) {
			$own = self::own_names();
			if ( $own ) {
				$sig .= 'own:' . substr( md5( implode( ',', $own ) ), 0, 8 );
			}
		}
		return $sig;
	}

	/* ------------------------------------------------------------------ encoding */

	/** Any ⟦ ⟧ in words that came from outside the server is defused, so no one can hand the site a code. */
	public static function defuse( $s ) {
		return str_replace( array( self::OPEN, self::CLOSE ), array( '[', ']' ), (string) $s );
	}

	/**
	 * Plain words -> words with every private detail encoded. A ⟦ ⟧ already in the words is defused, unless $keep_codes
	 * says the codes in it came from this server and were checked (from_reader(), re-encoding a stored note) -- then
	 * they are left as they are. $idx is for tests; normally the site's index. $scope (['job', 12], …): seal each code
	 * into the vault under that record; without one a code is only cut out. Ignored for Hours (its own rule, see the
	 * class comment). $opts: company => true for words the business wrote (see the class comment): only the one-word
	 * name rules are off.
	 */
	public function encode( $text, $idx = null, $keep_codes = false, $scope = null, array $opts = array() ) {
		$company = ! empty( $opts['company'] );
		$text = $keep_codes ? self::defuse_partial( (string) $text ) : self::defuse( $text );
		if ( '' === trim( $text ) ) {
			return $text;
		}
		$cut = $this->cuts_codes();
		if ( $cut ) {
			// gate, lockbox, alarm, door and keypad codes first: "[gate code sealed]", never an ⟦e⟧
			$text = self::on_plain( $text, function ( $p ) use ( $scope ) {
				return ZDZ_Sealed::cut( $p, $scope );
			} );
		}
		$idx  = is_array( $idx ) ? $idx : $this->index();
		$seal = function ( $m ) { return $this->seal( $m[0] ); };
		// e-mails
		$text = self::on_plain( $text, function ( $p ) use ( $seal ) {
			return preg_replace_callback( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $seal, $p );
		} );
		// phone numbers: 10 digits in any usual layout, or a local 7 ("555-0142")
		$text = self::on_plain( $text, function ( $p ) use ( $seal ) {
			return preg_replace_callback( '/(?<![\d:])(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]\d{4}(?!\d)|(?<![\d:])\d{10}(?!\d)|(?<![\d:\-])\d{3}[.\-]\d{4}(?![\d\-])/u', $seal, $p );
		} );
		// a street address: a house number, one to four capitalised words, a street word written as one ("1450 Oak St",
		// "12 N Example Ave"). Lower-case words never are ("put 4 screens back into place", "40 miles down Highway 9"),
		// and a number right after another short number is a time ("ended 3 15 Elm St" keeps the 3 15).
		$text = self::on_plain( $text, function ( $p ) use ( $seal ) {
			$street = '(?:St|Street|Ave|Avenue|Rd|Road|Dr|Drive|Ln|Lane|Ct|Court|Pl|Place|Way|Blvd|Boulevard|Cir|Circle|Ter|Terrace|Pkwy|Parkway|Hwy|Highway|Trl|Trail|Loop|Row|Glen|Pt|Point|Cv|Cove|Sq|Square|Real|Camino|Calle|Via)';
			return preg_replace_callback( '/(?<![\d:.])(?<!\d )\b\d{1,6}(?:\s+[NSEW]\.?)?(?:\s+(?:\p{Lu}[\p{L}\'.\-]*|\d+(?:st|nd|rd|th))){1,4}?\s+' . $street . '\b\.?(?:\s*(?:#|Apt\.?|apt\.?|Unit|unit|Ste\.?|Suite|suite)\s*[\w\-]+)?/u', $seal, $p );
		} );
		if ( ! $cut ) {
			// the Hours app's own stored notes only (see the class comment): a code word, then the digits, sealed as ⟦e⟧
			$text = self::on_plain( $text, function ( $p ) use ( $seal ) {
				$p = preg_replace_callback( '/\b(?:gate|door|lock\s?box|alarm|garage|keypad|access)\s*(?:code|#)\s*(?:is\s*)?[:#]?\s*[\d#*]{3,10}(?![\d])/iu', $seal, $p );
				$p = preg_replace_callback( '/\bcode\s*(?:is\s*)?[:#]?\s*[\d#*]{3,10}(?!\d)(?:\s+(?:at|for|on)\s+the\s+(?:gate|door|garage))?/iu', $seal, $p );
				return $p;
			} );
		}
		return self::on_plain( $text, function ( $p ) use ( $idx, $company ) {
			return $this->encode_names( $p, $idx, $company );
		} );
	}

	/**
	 * Customer names only (the word windows), no e-mail, phone, street or code rules -- for text where a number may be
	 * an id, a colour or a code, not a phone (a privacy view's quiet pass). Ledger names become ⟦c⟧, others ⟦e⟧.
	 */
	public function names_only( $text ) {
		$text = self::defuse( (string) $text );
		if ( '' === trim( $text ) ) {
			return $text;
		}
		$idx = $this->index();
		return self::on_plain( $text, function ( $p ) use ( $idx ) {
			return $this->encode_names( $p, $idx, false );
		} );
	}

	/** Run $fn on the plain stretches of $text only, never inside a code already made. */
	private static function on_plain( $text, callable $fn ) {
		$parts = preg_split( '/(' . self::OPEN . '[ce]:[A-Za-z0-9_\-]*' . self::CLOSE . ')/u', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $p ) {
			if ( 0 === $i % 2 && '' !== $p ) {
				$parts[ $i ] = (string) $fn( $p );
			}
		}
		return implode( '', $parts );
	}

	/**
	 * Customer names by word windows (4..2 words), then a lone surname in a work phrase and a first name in a contact
	 * phrase. Company text: the windows only.
	 */
	private function encode_names( $p, array $idx, $company = false ) {
		if ( ! preg_match_all( '/[\p{L}][\p{L}\'’.\-]*/u', $p, $mm, PREG_OFFSET_CAPTURE ) ) {
			return $p;
		}
		$common = self::common_words();
		$words  = $mm[0]; // [text, byte offset]
		$n      = count( $words );
		$spans  = array(); // [start, end, replacement]
		$taken  = array();
		for ( $i = 0; $i < $n; $i++ ) {
			if ( isset( $taken[ $i ] ) ) {
				continue;
			}
			for ( $len = min( 4, $n - $i ); $len >= 2; $len-- ) {
				$start = $words[ $i ][1];
				$last  = $words[ $i + $len - 1 ];
				$end   = $last[1] + strlen( $last[0] );
				$chunk = substr( $p, $start, $end - $start );
				$reversed = false;
				if ( preg_match( '/[\n\r]|[.!?;:,]\s/u', $chunk ) ) {
					// a name never runs across a sentence or a list -- except "Example, Sam" (last, first)
					if ( 2 === $len && preg_match( '/^[\p{L}\'’\-]+,\s+[\p{L}\'’\-]+$/u', $chunk ) ) {
						$reversed = true;
					} else {
						continue;
					}
				}
				$core = preg_replace( '/[\'’]s$/u', '', $chunk );
				$k    = $reversed ? self::norm( $words[ $i + 1 ][0] . ' ' . $words[ $i ][0] ) : self::norm( $core );
				if ( isset( $idx['team'][ $k ] ) ) {
					break;
				}
				$code = null;
				if ( isset( $idx['ledger'][ $k ] ) ) {
					$code = self::OPEN . 'c:' . (int) $idx['ledger'][ $k ] . self::CLOSE;
				} elseif ( isset( $idx['other'][ $k ] ) ) {
					$code = $this->seal( $core );
				}
				if ( $code ) {
					$tail = substr( $chunk, strlen( $core ) );
					$spans[] = array( $start, $end, $code . $tail );
					for ( $j = $i; $j < $i + $len; $j++ ) {
						$taken[ $j ] = true;
					}
					break;
				}
			}
		}
		$firsts = self::LEGACY_HOURS !== $this->purpose && ! empty( $idx['first'] );   // never Hours: its own output
		if ( $company && self::LEGACY_HOURS !== $this->purpose ) {
			// company text: a person's name the list knows, written in another order or without a middle word ("Sam
			// Example", "Example, Sam", "Example, Lee Sam") is still a name; "Example Vent" stays
			$variants = $this->name_variants( $idx );
			for ( $i = 0; $i < $n && $variants; $i++ ) {
				for ( $len = min( 3, $n - $i ); $len >= 2; $len-- ) {
					$free = true;
					$fits = true;
					for ( $j = $i; $j < $i + $len; $j++ ) {
						$free = $free && ! isset( $taken[ $j ] );
						if ( $j > $i ) {
							$from = $words[ $j - 1 ][1] + strlen( $words[ $j - 1 ][0] );
							$gap  = substr( $p, $from, $words[ $j ][1] - $from );
							$fits = $fits && ( preg_match( '/^[ \t]+$/u', $gap ) || ( $j === $i + 1 && preg_match( '/^,[ \t]+$/u', $gap ) ) );
						}
					}
					if ( ! $free || ! $fits ) {
						continue;
					}
					$last  = $words[ $i + $len - 1 ];
					$end   = $last[1] + strlen( rtrim( preg_replace( '/[\'’]s$/u', '', $last[0] ), '.' ) );
					$chunk = substr( $p, $words[ $i ][1], $end - $words[ $i ][1] );
					if ( isset( $variants[ self::norm( $chunk ) ] ) ) {
						$spans[] = array( $words[ $i ][1], $end, $this->seal( $chunk ) );
						for ( $j = $i; $j < $i + $len; $j++ ) {
							$taken[ $j ] = true;
						}
						break;
					}
				}
			}
		}
		$place = ( $company || self::LEGACY_HOURS === $this->purpose ) ? array() : self::place_words( $p, $words, $taken );
		for ( $i = 0; $i < $n && ! $company; $i++ ) {
			if ( isset( $taken[ $i ] ) || isset( $place[ $i ] ) ) {   // a word of a place written whole
				continue;
			}
			$raw  = $words[ $i ][0];
			$bare = preg_replace( '/[\'’]s$/u', '', $raw );
			$k    = self::norm( $bare );
			$fk   = $firsts ? $this->first_key( $raw ) : '';
			if ( '' !== $fk && isset( $idx['first'][ $fk ] ) && ! isset( $idx['team'][ $fk ] ) && $this->contact_phrase( $p, $words, $i ) ) {
				$name    = rtrim( $bare, '.' );   // "spoke to Sam. Sam said …": the stop stays, one code
				$spans[] = array( $words[ $i ][1], $words[ $i ][1] + strlen( $name ), $this->seal( $name ) );
				continue;
			}
			if ( ( ! isset( $idx['surname'][ $k ] ) || isset( $idx['team'][ $k ] ) ) && false !== strpos( $k, ' ' ) ) {
				// "Sam-Example": norm() made it two words; the surname is its last part
				$parts_k = explode( ' ', $k );
				$last_k  = end( $parts_k );
				// a common last part ("Example-Friday") still counts when another part is not common; "Mon-Friday"
				// stays as written
				$plain_all = ! array_diff_key( array_flip( $parts_k ), $common );
				if ( isset( $idx['surname'][ $last_k ] ) && ! isset( $idx['team'][ $last_k ] ) && ( ! isset( $common[ $last_k ] ) || ! $plain_all ) ) {
					$spans[] = array( $words[ $i ][1], $words[ $i ][1] + strlen( $bare ), $this->seal( $bare ) );
					continue;
				}
			}
			if ( ! isset( $idx['surname'][ $k ] ) || isset( $idx['team'][ $k ] ) ) {
				// "the Examples" (a family) -> the singular
				$k2 = preg_replace( '/s$/', '', $k );
				if ( $k2 === $k || ! isset( $idx['surname'][ $k2 ] ) || isset( $idx['team'][ $k2 ] ) ) {
					continue;
				}
				$k = $k2;
			}
			$prev  = $i > 0 ? self::norm( $words[ $i - 1 ][0] ) : '';
			$next  = $i + 1 < $n ? self::norm( $words[ $i + 1 ][0] ) : '';
			$poss  = $bare !== $raw;
			// a known (person) surname written with a capital is a name, at the start of a note too ("Example 8-12")
			$caps  = (bool) preg_match( '/^\p{Lu}/u', $raw );
			$hit   = isset( $common[ $k ] )
				// a word that is also plain English ("Wood", "Price", "Smith") only in a plain customer phrase; after a
				// title too ("Mrs Thursday"), but a day or month never by the word after it ("Thursday job tomorrow"
				// keeps its day)
				? ( ( in_array( $next, self::AFTER, true ) && ! in_array( $k, self::CALENDAR, true ) ) || ( $poss && preg_match( '/^\p{Lu}/u', $raw ) )
					|| in_array( $prev, self::TITLES, true ) )
				: ( in_array( $prev, self::BEFORE, true ) || in_array( $next, self::AFTER, true ) || $poss || $caps );
			if ( $hit ) {
				$spans[] = array( $words[ $i ][1], $words[ $i ][1] + strlen( $bare ), $this->seal( $bare ) );
			}
		}
		usort( $spans, function ( $a, $b ) { return $b[0] <=> $a[0]; } );
		foreach ( $spans as $s ) {
			$p = substr( $p, 0, $s[0] ) . $s[2] . substr( $p, $s[1] );
		}
		return $p;
	}

	/**
	 * The indexes of words that stand in a place of places() written whole ("Lake Example", "in north example",
	 * "Port Example City's"), with only spaces between its words. Words a full name already took are not looked at again.
	 */
	private static function place_words( $p, array $words, array $taken ) {
		$by_first = self::lists()['places'];
		$out      = array();
		if ( ! $by_first ) {
			return $out;
		}
		$n = count( $words );
		for ( $i = 0; $i < $n; $i++ ) {
			$k = self::norm( preg_replace( '/[\'’]s$/u', '', $words[ $i ][0] ) );
			if ( isset( $taken[ $i ] ) || ! isset( $by_first[ $k ] ) ) {
				continue;
			}
			$best = 0;
			foreach ( $by_first[ $k ] as $w ) {
				$len = count( $w );
				if ( $len <= $best || $i + $len > $n ) {
					continue;
				}
				$ok = true;
				for ( $j = 1; $j < $len && $ok; $j++ ) {
					$from = $words[ $i + $j - 1 ][1] + strlen( $words[ $i + $j - 1 ][0] );
					$gap  = substr( $p, $from, $words[ $i + $j ][1] - $from );
					$wj   = self::norm( preg_replace( '/[\'’]s$/u', '', $words[ $i + $j ][0] ) );
					$ok   = ! isset( $taken[ $i + $j ] ) && preg_match( '/^[ \t]+$/u', $gap ) && $wj === $w[ $j ]
						&& ( $j === $len - 1 || ! preg_match( '/[\'’]s$/u', $words[ $i + $j ][0] ) );
				}
				if ( $ok && ! preg_match( '/[\'’]s$/u', $words[ $i ][0] ) ) {
					$best = $len;
				}
			}
			for ( $j = 0; $j < $best; $j++ ) {
				$out[ $i + $j ] = true;
			}
			if ( $best ) {
				$i += $best - 1;
			}
		}
		return $out;
	}

	/**
	 * A word as a first-name key: lower case, no possessive or stop, the first part of a hyphenated name ("Sam-Lee" ->
	 * sam); '' for a common word, an everyday one, or one a plugin says is never a name.
	 */
	private function first_key( $raw ) {
		$k = self::norm( preg_replace( '/[\'’]s$/u', '', rtrim( (string) $raw, '.' ) ) );
		if ( false !== strpos( $k, ' ' ) ) {
			$k = strstr( $k, ' ', true );
		}
		if ( '' === $k || self::is_common( $k ) || in_array( $k, self::PLAIN_FIRST, true ) ) {
			return '';
		}
		if ( null === $this->memo_plain ) {
			// a plugin's own words (a catalog's) are never a first name: "Upgrade To Premier"
			$this->memo_plain = array();
			if ( function_exists( 'apply_filters' ) ) {
				foreach ( (array) apply_filters( 'zdz_private_not_first_names', array(), $this->purpose ) as $w ) {
					foreach ( explode( ' ', self::norm( (string) $w ) ) as $x ) {
						if ( '' !== $x ) {
							$this->memo_plain[ $x ] = true;
						}
					}
				}
			}
		}
		return isset( $this->memo_plain[ $k ] ) ? '' : $k;
	}

	/**
	 * A person's name (two or three words, no business word) the list knows, in the orders it may be written in company
	 * text: first last, last first, and for three words also last first middle, middle last and last middle ("Lee Sam
	 * Example": "example lee sam", "sam example", "example sam"). Team names never, whole or as a pair. Kept per list.
	 */
	private function name_variants( array $idx ) {
		$sig = (string) ( $idx['sig'] ?? '' ) . '|' . count( (array) ( $idx['ledger'] ?? array() ) ) . '|' . count( (array) ( $idx['other'] ?? array() ) ) . '|' . md5( implode( ',', array_slice( array_keys( (array) ( $idx['ledger'] ?? array() ) ), 0, 50 ) ) );
		if ( $this->memo_var[0] === $sig ) {
			return $this->memo_var[1];
		}
		$out = array();
		foreach ( array_merge( array_keys( (array) ( $idx['ledger'] ?? array() ) ), array_keys( (array) ( $idx['other'] ?? array() ) ) ) as $k ) {
			$w = explode( ' ', (string) $k );
			while ( count( $w ) > 2 && in_array( $w[0], array( 'mr', 'mrs', 'ms', 'miss', 'dr' ), true ) ) {
				array_shift( $w );
			}
			$c = count( $w );
			if ( $c < 2 || $c > 3 || array_intersect( $w, self::BUSINESS ) || strlen( end( $w ) ) < 3 || isset( $idx['team'][ implode( ' ', $w ) ] ) ) {
				continue;
			}
			$f = $w[0];
			$l = $w[ $c - 1 ];
			// a pair that is a team member's name, in either order, is never a variant (the customer "Robin Lee Casey"
			// must not code the team member "Robin Casey"), as the word-window pass above already skips a team name
			$add = function ( $a, $b, $rest = '' ) use ( &$out, $idx ) {
				if ( isset( $idx['team'][ $a . ' ' . $b ] ) || isset( $idx['team'][ $b . ' ' . $a ] ) ) {
					return;
				}
				$out[ $a . ' ' . $b . ( '' !== $rest ? ' ' . $rest : '' ) ] = true;
			};
			$add( $f, $l );
			$add( $l, $f );
			if ( 3 === $c ) {
				$out[ $l . ' ' . $f . ' ' . $w[1] ] = true;   // "Example, Lee Sam": the whole name, its team check is above
				$add( $w[1], $l );                            // "Sam Example" (a middle or second first name)
				$add( $l, $w[1] );
			}
		}
		$this->memo_var = array( $sig, $out );
		return $out;
	}

	/** Text with its codes taken out (the router's company words). */
	public static function on_plain_text( $text ) {
		return (string) preg_replace( '/' . self::OPEN . '[ce]:[A-Za-z0-9_\-]*' . self::CLOSE . '/u', ' ', (string) $text );
	}

	/**
	 * Word $i stands in a contact phrase:
	 *   after a contact verb or title, any case            "called sam", "sp sam", "left vm sam", "Mrs Sam"
	 *   after to / with / from / for / per / w             with a capital ("spoke to Sam"), or with a contact verb before
	 *                                                      ("sp to sam", "left vm for sam"); after "per" also when the
	 *                                                      name ends the clause ("pd per sam", "per sam, will pay");
	 *                                                      "W" only as "w/" or after a contact verb (not "W Maple St")
	 *   before called / says / said / texted / emailed     opening the note or a sentence, or with a capital ("then
	 *                                                      Sam says"); lower case mid-sentence only before called /
	 *                                                      texted / emailed ("then sam called back"; "the tag says" stays)
	 *   before will / wants / paid / calls                  opening the note or a sentence ("Sam will pay Friday")
	 * After a contact word a stop or a comma between does not matter ("called. Sam …"): in doubt, the name is coded.
	 */
	private function contact_phrase( $p, array $words, $i ) {
		$caps  = (bool) preg_match( '/^\p{Lu}/u', $words[ $i ][0] );
		$prev  = $i > 0 ? self::norm( $words[ $i - 1 ][0] ) : '';
		$prev2 = $i > 1 ? self::norm( $words[ $i - 2 ][0] ) : '';
		$next  = $i + 1 < count( $words ) ? self::norm( $words[ $i + 1 ][0] ) : '';
		$gap   = function ( $a, $b ) use ( $p, $words ) {
			$from = $words[ $a ][1] + strlen( rtrim( $words[ $a ][0], '.' ) );
			return substr( $p, $from, $words[ $b ][1] - $from );
		};
		$apart  = function ( $a, $b ) use ( $gap ) {   // a stop between word $a and word $b
			return (bool) preg_match( '/[\n\r.!?;:]/u', $gap( $a, $b ) );
		};
		$strong = function ( $w ) { return '' !== $w && in_array( $w, self::CONTACT, true ) && ! in_array( $w, self::CONTACT_WEAK, true ); };
		if ( '' !== $prev && in_array( $prev, self::CONTACT, true ) ) {
			if ( 'w' === $prev && ! $strong( $prev2 ) && false === strpos( $gap( $i - 1, $i ), '/' ) ) {
				// "W" alone is a compass point ("Install W Maple St"); "w/" and "sp w" are "with"
			} elseif ( $caps || 'w' === $prev || $strong( $prev ) || $strong( $prev2 ) ) {
				return true;   // "spoke to Sam", "called sam", "sp to sam", "spoke with sam", "sp w/ Sam"
			} elseif ( 'per' === $prev ) {
				$after = $i + 1 < count( $words ) ? $gap( $i, $i + 1 ) : '';
				$rest  = substr( $p, $words[ $i ][1] + strlen( $words[ $i ][0] ) );
				if ( '' === trim( $rest ) || preg_match( '/[,;.!?\n\r]/u', $after ) || preg_match( '/^[,;.!?]/u', ltrim( $rest ) ) ) {
					return true;   // "pd per sam", "per sam, will pay"; "per tag size" stays
				}
			}
		}
		if ( '' !== $next && in_array( $next, self::CONTACT_NEXT, true ) && ! $apart( $i, $i + 1 ) ) {
			if ( 0 === $i || $apart( $i - 1, $i ) ) {
				return true;   // "Sam called", "sam says" at the start of a note or a sentence
			}
			if ( in_array( $next, self::CONTACT_VERB, true ) && ( $caps || in_array( $next, self::CONTACT_DID, true ) ) ) {
				return true;   // "then Sam says", "then sam called back"
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ decoding */

	/** Forget this request's name lookups (a new request starts empty anyway; tests and long runs). */
	public function forget() {
		$this->memo_name  = array();
		$this->memo_idx   = null;
		$this->memo_plain = null;
		$this->memo_var   = array( '', array() );
		self::$lists      = null;
	}

	/** The ledger's name for a client id (the on-site copy; never the billing system itself). Once per request. */
	public function ledger_name( $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		if ( array_key_exists( $id, $this->memo_name ) ) {
			return $this->memo_name[ $id ];
		}
		global $wpdb;
		$name = null;
		foreach ( $this->ledger_tables() as $t ) {
			$v = $wpdb->get_var( $wpdb->prepare( "SELECT customer_name FROM {$t} WHERE customer_id = %d AND customer_name <> '' ORDER BY vis_state = 1, customer_id LIMIT 1", $id ) ); // phpcs:ignore
			if ( is_string( $v ) && '' !== $v ) {
				$name = $v;
				break;
			}
		}
		$this->memo_name[ $id ] = $name;
		return $name;
	}

	/**
	 * Codes -> what they stand for ($show true; only in an answer to the writer or the admin tier), or -> "a customer" /
	 * "[private]" ($show false: exports, logs, anyone else).
	 */
	public function decode( $text, $show ) {
		return preg_replace_callback( '/' . self::OPEN . '([ce]):([A-Za-z0-9_\-]*)' . self::CLOSE . '/u', function ( $m ) use ( $show ) {
			if ( 'c' === $m[1] ) {
				if ( ! $show ) {
					return 'a customer';
				}
				$n = $this->ledger_name( (int) $m[2] );
				return null === $n ? 'a customer' : $n;
			}
			if ( ! $show ) {
				return '[private]';
			}
			$o = $this->open( $m[2] );
			return null === $o ? '[private]' : $o;
		}, (string) $text );
	}

	/** Brackets that are not part of a well-formed code are defused (a stray ⟦ must not join with what follows). */
	private static function defuse_partial( $s ) {
		$parts = preg_split( '/(' . self::OPEN . '[ce]:[A-Za-z0-9_\-]*' . self::CLOSE . ')/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $p ) {
			if ( 0 === $i % 2 ) {
				$parts[ $i ] = self::defuse( $p );
			}
		}
		return implode( '', $parts );
	}

	public static function has_codes( $text ) {
		return false !== strpos( (string) $text, self::OPEN );
	}

	/** The ⟦e:…⟧ codes in a text. */
	public static function sealed_codes( $text ) {
		preg_match_all( '/' . self::OPEN . 'e:([A-Za-z0-9_\-]+)' . self::CLOSE . '/u', (string) $text, $m );
		return $m[1];
	}

	/** The ⟦c:…⟧ codes in a text. */
	public static function codes( $text ) {
		preg_match_all( '/' . self::OPEN . 'c:(\d+)' . self::CLOSE . '/u', (string) $text, $m );
		return array_map( 'intval', $m[1] );
	}

	/**
	 * What a reader (a model) wrote back: keep a ⟦c:…⟧ only when it was in what it was given; ⟦e:…⟧ only when it opens
	 * under this purpose's key (and, with $allowed_e, only those it was sent); anything else private it wrote is encoded
	 * like any other words.
	 */
	public function from_reader( $text, array $allowed_c, $idx = null, array $allowed_e = null ) {
		$text = preg_replace_callback( '/' . self::OPEN . '([ce]):([A-Za-z0-9_\-]*)' . self::CLOSE . '/u', function ( $m ) use ( $allowed_c, $allowed_e ) {
			if ( 'c' === $m[1] ) {
				return in_array( (int) $m[2], $allowed_c, true ) ? $m[0] : 'a customer';
			}
			if ( null !== $allowed_e && ! in_array( $m[2], $allowed_e, true ) ) {
				return '[private]'; // only the sealed codes it was given
			}
			return null === $this->open( $m[2] ) ? 'a customer' : $m[0];
		}, self::defuse_partial( (string) $text ) );
		return $this->encode( $text, $idx, true );
	}
}
