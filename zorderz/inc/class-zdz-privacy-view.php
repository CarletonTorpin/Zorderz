<?php
/**
 * ZDZ_Privacy_View -- a per-user mode in which no customer's details, and no team member's name, reach the browser.
 *
 * An administrator switches it on for another account (on that account's profile; it is off by default and never on
 * an administrator's own account). That account sees stable-within-a-reply pseudonyms instead of customer details,
 * team members coded by a different method, cannot see keys or change anything, and photos are placeholders. It suits
 * an account that should work with the business's records without learning who they belong to: an assistant's
 * account, a demonstration, an outside contractor.
 *
 * The guarantees, in the order they come from:
 *
 *  1. DENY BY DEFAULT. The account reaches only listed surfaces: the chat's own AJAX actions, a few REST reads, the Ai
 *     usage screen and its own profile. Every other AJAX action, REST route, wp-admin screen and POST is refused, with
 *     no guessing from names. A surface joins the list only with its own masking and tests. A listed request is read
 *     WHOLE, not by its name: an allowed action carries only its own fields, by its own method, to admin-ajax.php
 *     itself, its action written once; the address as written is what PHP read from it; no body is left that PHP did
 *     not turn into those fields; no file is uploaded. One thing more and it is refused, with one word for what was
 *     wrong (field, file, body, action, address, method, header, not listed) and never anything the request wrote.
 *     The account keeps only its sign-in cookies; a request header some code acts on refuses the request.
 *  2. BY FIELD. Every JSON reply is walked as JSON: a field that holds a person or company (customer, client, contact
 *     ...) becomes a pseudonym whatever is in it, known or not; an e-mail, phone or street field likewise; an invoice
 *     or estimate number field likewise. No search is involved for those.
 *  3. PROSE. Free text (an answer, a title, the debug trace's data) goes through the coder (every customer, e-mail,
 *     phone, street and gate code it knows), team members' names and initials, streets written out, invoice and
 *     estimate numbers (labelled, #NNN, and any number the ledger holds as one), and then THE NAME RULE: a capitalised
 *     word is a name unless a word list says it is ordinary (inc/data/privacy-view-words.php: frequent English less
 *     census names; the safe vocabulary below; places and safe phrases as phrases), plus first name + surname-like
 *     word, titles, "Surname, First", greetings, across Markdown, nbsp, one line break and inline tags. Masked words
 *     become "[name]". Every string in a JSON reply gets this pass unless its key is a machine key.
 *  4. NO WAY AROUND IT. The gate runs at after_setup_theme (before any plugin's init); with the optional must-use
 *     file installed (Zorderz -> Privacy View) it also runs before any plugin's file loads, and again at
 *     plugins_loaded first of all (early_gate()). A reply path that clears the output buffers masks itself; the photo
 *     server sends a placeholder; every other response passes the output filter, which holds chunks to the end, checks
 *     there is memory to mask before it starts (a fatal error inside an output handler would send its input as it
 *     is), refuses anything it cannot read, refuses on any error, and starts again after WordPress's last flush.
 *
 * Every reply the account gets has its own random tag (four consonants), and every pseudonym in it is Tag·CODE: one
 * shape for customers, team, streets, e-mails, phones, invoices, estimates and photos. Within one reply a thing keeps
 * one code (a list still reads); the next reply has new codes, so none of them becomes an identifier over time, and
 * nothing in a pseudonym says what kind of thing it hides. Customers are coded from the name's normal form, team
 * members from their WordPress user id under a key of their own. Each code ends in a check character (weights 1-5,
 * sum mod 37): a one-character slip or a neighbour swap never names another record. Keys come from the site's salts
 * under purposes of their own. Only an administrator's look-up (Zorderz -> Privacy View) turns a pseudonym back, by
 * reading the tag and working the codes out again with the site's key; nothing is stored.
 *
 * The safe vocabulary is Core's generic list plus the business's own: the words of its names (Business Profile), its
 * catalog's product words (Item Engine, price book), and the words and phrases an Identity Pack adds under
 * knowledge.yml `privacy_view` or a plugin adds on the `zdz_privacy_view_words` filter. Anything not listed stays a
 * name: an unknown word is masked, never let through.
 *
 * It needs the coder (ZDZ_Private). Without it every response to the account is refused, never sent unmasked.
 *
 * Other apps take part through (declared_now() lists what each filter is handed and may add; an app adds its filters
 * as its file loads or on plugins_loaded, never later):
 *   - zdz_privacy_view_allowed_ajax / zdz_privacy_view_allowed_rest  add an action or route once it masks its own
 *     output and has tests; zdz_privacy_view_ajax_keys names the fields each added action's requests carry (none
 *     named, none allowed; an added action comes by POST and uploads nothing; a route is read, never written);
 *   - zdz_privacy_view_allowed_apps  the app says it is one made safe for the account, so the app shell lists it;
 *   - zdz_privacy_view_hidden_controls  selectors of its own buttons the account may not use (not drawn for it);
 *   - zdz_privacy_view_signin_rest, zdz_privacy_view_trigger_headers, zdz_privacy_view_login_keys  a site's own
 *     sign-in routes, and the headers and wp-login.php marker its host or page cache uses;
 *   - zdz_privacy_view_role_words, zdz_privacy_view_system_words  words of the business's own for the prose rules;
 *   - zdz_privacy_view_real_photo ($bool, $media_row)  answer true for a photo that may be shown as it is (re-drawn,
 *     without EXIF or location);
 *   - zdz_privacy_view_shown ($tag)  a whole reply was masked under this tag (a chat keeps it, so a code it showed
 *     can be read back later with resolve_in());
 *   - the messaging app: its direct-message actions open to the account only when an administrator has chosen the one
 *     person it may message (dm_partner()) and the messaging app narrows every such action to that one thread
 *     (class ZIM_Privacy_View with action_ok() and conversation_ok()).
 *
 * Known and deliberate: a name written in lower case or deliberately obfuscated; a name that is an ordinary or listed
 * word on its own ("Robin said"); a company named only with ordinary words; files a web server serves directly (never
 * handed to the account).
 *
 * @package Zorderz
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Privacy_View {

	const VERSION    = '1.0.0';
	const META       = 'zdz_privacy_view';
	const PAGE       = 'zdz-privacy-view';
	const VOCAB_OPT  = 'zdz_privacy_view_vocabulary';   // array( 'words' => [], 'phrases' => [] ), written by an Identity Pack
	const ABC        = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
	const MID        = '·';
	const BANNER_ID  = 'zdz-pv-banner';
	/** Controls the account may not use, hidden on its pages: the messaging app's edit, delete, select and swipe-delete
	 *  (the server refuses each of them for this account anyway). Plain class or id selectors; an app adds its own on
	 *  the zdz_privacy_view_hidden_controls filter (hidden_controls()). */
	const HIDDEN_CONTROLS = array( '.zim-w-msg-actions', '.zim-w-select-btn', '.zim-w-swipe-zone' );
	const NAME_GONE  = '[name]';
	const MAX_BYTES  = 1500000;   // a response larger than this is refused before masking (no out-of-memory path)
	const CHUNK      = 16384;     // PHP hands the filter the output in pieces this size; the filter holds them itself
	const MEM_PER_BYTE = 80;      // what masking costs in memory per byte of output (measured 20-60 with the name rule), with room

	/**
	 * 1. THE LISTS (deny by default).
	 *
	 * The only admin-ajax actions the account may run: the chat's own road for it, the Projects app's two reads and the
	 * app shell's keep-alive. Exact names; nothing is inferred. Search over past chats is NOT here: it matches the
	 * stored (unmasked) text, so a guess could be tested letter by letter. The chat's server-to-server calls are not
	 * here either: the account never sends them.
	 *
	 * The keep-alive hands the page a fresh REST nonce. It is listed because its reply carries that nonce under the key
	 * "nonce", which the second net keeps whole (a machine key); under any other key the nonce could come back
	 * rewritten as a pseudonym, and every REST call after it would be refused. An action that hands the account a nonce
	 * joins this list only with a test that the second net keeps its reply's nonce.
	 */
	const ALLOWED_AJAX = array(
		'zana_send_message', 'zana_load_session', 'zana_list_sessions', 'zana_list_memories', 'zana_cancel_pending_action',
		'zana_refresh_nonce',
		// Projects: its list and one project, read only (the jobs app sends this account photo addresses through the
		// photo server only, writes nothing on view and draws no button that writes)
		'zdz_jobs_projects_list', 'zdz_jobs_project_detail',
		// the app shell's keep-alive
		'zdz_fresh_nonce',
	);
	/**
	 * THE WHOLE REQUEST, NOT ITS NAME. WordPress runs every plugin's init and admin_init handlers on admin-ajax.php
	 * before the action's own handler, so a settings handler that looks for its own fields (a save button's name, a
	 * sign-in return's ?page=...&code=...) would run beside an allowed read, with the account's rights. So an allowed
	 * action may carry only its own fields, listed here by name: one field more and the request is refused at the gate,
	 * before init. "action" and "nonce" are every row's. Each row is read from the action's handler (what it takes) and
	 * its script (what it sends); every action in ALLOWED_AJAX and DM_AJAX has a row, and a test holds the rows to what
	 * the shipped scripts send. A script the account runs that gains a field needs the name added HERE, or the account
	 * is refused (403, why: "field"). No filter changes a row; an app declares rows for its own actions (ajax_keys()).
	 */
	const AJAX_BASE_KEYS = array( 'action', 'nonce' );
	const AJAX_KEYS = array(
		// the chat
		'zana_send_message'          => array( 'message', 'session_id' ),
		'zana_load_session'          => array( 'session_id' ),
		'zana_list_sessions'         => array(),
		'zana_list_memories'         => array(),
		'zana_cancel_pending_action' => array( 'id', 'session_id' ),
		'zana_refresh_nonce'         => array(),
		// Projects
		'zdz_jobs_projects_list'     => array( 'view' ),
		'zdz_jobs_project_detail'    => array( 'project_id' ),
		// the app shell's keep-alive ("action" alone)
		'zdz_fresh_nonce'            => array(),
		// its one direct-message thread (DM_AJAX): the chat's send, and the messaging app's five
		'zana_confirm_zim_dm'        => array( 'id', 'session_id' ),
		'zim_sidebar'                => array(),
		'zim_fetch_before'           => array( 'conversation_id', 'before' ),
		'zim_poll'                   => array( 'conversation_id', 'since' ),
		'zim_mark_read'              => array( 'conversation_id', 'message_id' ),
		'zim_post'                   => array( 'conversation_id', 'body', 'attachment_ids', 'share_refs' ),
	);
	/** The only actions sent by GET, with their fields in the address (the messaging app's three reads). Every other
	 *  action comes by POST with nothing in the address at all, so no field name of the body's can be written there.
	 *  The account uploads nothing: a file field, on any action, refuses the request. */
	const AJAX_GET = array( 'zim_sidebar', 'zim_fetch_before', 'zim_poll' );
	/** The only query keys a REST call may carry (the route itself where the site has no pretty addresses; the nonce). */
	const REST_KEYS = array( 'rest_route', '_wpnonce' );
	/** The only fields wp-login.php may carry for the account, by GET: signing out, and the pages around it. It is
	 *  signed in already, so the sign-in form's own fields are not here (someone signing in is not yet this account).
	 *  A host that adds a marker of its own to every wp-login.php address names it on zdz_privacy_view_login_keys. */
	const WPLOGIN_KEYS = array( 'action', '_wpnonce', 'redirect_to', 'loggedout', 'reauth', 'interim-login', 'wp_lang' );
	/** The sign-in form's fields and its links' keys: never a wp-login.php key for the account, whatever a filter adds. */
	const WPLOGIN_NEVER = array( 'log', 'pwd', 'user_login', 'user_pass', 'user_email', 'rememberme', 'testcookie', 'wp-submit', 'key', 'login',
		'rp_key', 'token', 'user_id', 'page', 'code', 'state', 'rest_route', '_method' );
	/** The only wp-admin screens, each with the only query keys it may carry. */
	const ALLOWED_SCREENS = array( 'profile.php' => array(), 'admin.php?page=zdz-llm-usage' => array( 'page' ) );
	/** The only apps the app shell lists for the account (ZDZ_Plugin_API): the chat and Projects (read only). An app
	 *  that masks its own replies and refuses everything else itself adds its id on zdz_privacy_view_allowed_apps. */
	const ALLOWED_APPS = array( 'sales-analytics', 'projects' );
	/** Front end: the app shell itself, and a photo (a placeholder). Each with the only query keys it may carry. */
	const FRONT_KEYS = array( '' => array( 'cb' ), 'zdz_media' => array( 'zdz_media', 't', 's', 'v' ) );
	/** The docked direct-message thread page: exactly these keys (and an optional cache-buster). */
	const DM_PAGE_KEYS = array( 'zim_page', 'zdz_embed', 'zim_compact', 'c' );
	/**
	 * THE COOKIES THE ACCOUNT KEEPS. Every other cookie is taken out of the request before anything reads it ($_COOKIE,
	 * the Cookie header and $_REQUEST), by the early gate before any plugin loads and again at the gate. A cookie is a
	 * trigger like a field: a page cache may treat a request differently for one, and View As switches people by one.
	 * Taken out rather than refused, because a browser carries cookies the app never set (a proxy's, an analytics
	 * script's) and a refusal for one of those would lock the account out for nothing; nothing on the server needs
	 * them. Kept: WordPress's sign-in cookies (any site hash), its cookie test and screen settings, the sign-in
	 * language, and the app's "come back here after signing in" path. Patterns, read against the name as PHP keys it.
	 */
	const COOKIE_KEEP = array( '/\Awordpress_logged_in_[A-Za-z0-9]+\z/', '/\Awordpress_sec_[A-Za-z0-9]+\z/', '/\Awordpress_[a-f0-9]{32}\z/',
		'/\Awordpress_test_cookie\z/', '/\Awp-settings-(?:time-)?[0-9]+\z/', '/\Awp_lang\z/', '/\Azdz_next\z/' );
	/**
	 * REQUEST HEADERS THAT ARE TRIGGERS. A browser and the proxies in front of a site add many headers, so headers are
	 * not listed the way fields are; the ones some code acts on are refused outright (why: "header"). Core names the
	 * method and address overrides some routers act on (X-HTTP-Method-Override is refused already, as a method
	 * override). A site whose host or page cache acts on a header of its own names it on
	 * zdz_privacy_view_trigger_headers (trigger_headers()). Each is the start of a $_SERVER name.
	 */
	const TRIGGER_HEADERS = array( 'HTTP_X_METHOD_OVERRIDE', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL' );
	/**
	 * THE SIGN-IN ROUTES a signed-out browser uses (the login page's bridge), under the REST namespace. The early gate
	 * lets these, and wp-login.php, by before WordPress can say who is signed in, because a browser can still carry the
	 * account's cookie after its session has ended (signed out elsewhere, or the site's keys changed) and must be able
	 * to sign in again. They are judged as soon as WordPress can say who it is (plugins_loaded): a request that really
	 * is the account's is held to the lists as everything else is, so the account itself gets none of them. A new
	 * sign-in route is named on zdz_privacy_view_signin_rest (signin_rest()).
	 */
	const SIGNIN_ROUTES = array( 'magic-link-init', 'magic-link-status', 'magic-link-claim', 'magic-link-code-claim', 'magic-link-send-code' );
	/** The app's own sign-in pages (the theme's sign-in page is /login/; the bridge sends a phone back to
	 *  /login/?magic-login-bridge=...), by GET, with only these keys. Same rule as the sign-in routes. */
	const SIGNIN_PAGES     = array( 'login', 'register' );
	const SIGNIN_PAGE_KEYS = array( 'magic-login-bridge', 'request_id', 'bridge_token', 'bridge_code', 'zdz_next', 'redirect_to' );
	/** An ordinary form the account sends is a few hundred bytes; the gate reads no more of a body than this. */
	const FORM_MAX = 262144;
	/**
	 * What the site's apps declare on the filters, as an administrator's last wp-admin request saw it. The early gate
	 * (the must-use file) judges a request before any app has loaded, so it cannot ask them; it reads this note
	 * instead, and the gate proper (after_setup_theme) then asks the apps themselves. Kept only while that file is
	 * installed. array( 'ajax' => [ action => [ field, ... ] ], 'rest' => [], 'signin' => [], 'headers' => [], 'login' => [] ).
	 */
	const DECLARED_OPT = 'zdz_privacy_view_declared';

	/** 2. Fields by key (lower case, matched on the whole key or its last _part). */
	const NAME_KEYS   = array( 'customer', 'customer_name', 'client', 'client_name', 'contact', 'contact_name', 'organization',
		'organisation', 'company', 'company_name', 'billing_name', 'bill_to', 'billed_to', 'full_name', 'fname', 'lname',
		'first_name', 'last_name', 'display_name', 'homeowner', 'owner_name', 'lead_name', 'person', 'recipient', 'from_name', 'to_name',
		'business', 'customer_business', 'crm_customer_name' );
	const EMAIL_KEYS  = array( 'email', 'e_mail', 'email_address', 'billing_email', 'contact_email', 'from', 'to', 'cc', 'reply_to' );
	const PHONE_KEYS  = array( 'phone', 'telephone', 'tel', 'mobile', 'cell', 'fax', 'billing_phone', 'contact_phone', 'home_phone', 'work_phone' );
	const STREET_KEYS = array( 'street', 'street1', 'street2', 'address', 'address1', 'address2', 'address_line', 'address_line1',
		'address_line2', 'billing_street', 'contact_street', 'location_street', 'site_address', 'job_address', 'customer_address' );
	const DOC_KEYS    = array( 'invoice_number' => 'invoice', 'invoice_no' => 'invoice', 'invoice_num' => 'invoice',
		'estimate_number' => 'estimate', 'estimate_no' => 'estimate', 'estimate_num' => 'estimate' );
	/** 3. Keys whose text is prose (a person reads it): the full pass and the name allow-list. */
	const PROSE_KEYS  = array( 'response', 'content', 'text', 'title', 'message', 'answer', 'html', 'body', 'summary', 'note',
		'notes', 'data_text_full', 'data_text', 'user_query', 'preview', 'snippet', 'question', 'label', 'description', 'meta',
		'subject', 'name', 'sentence', 'memory', 'detail', 'details', 'reason', 'error', 'step_trail' );

	/** Keys that hold machine values (ids, nonces, URLs, states, colours): the quiet pass only. EVERY other string gets
	 *  the full pass (a quote, a partial answer, a plan line: whatever its key is called). Matched like the others, on
	 *  the whole key (camelCase read as snake_case) or its last part. */
	const MACHINE_KEYS = array( 'id', 'ids', 'uid', 'nonce', 'token', 'hash', 'status', 'type', 'kind', 'role', 'model',
		'url', 'href', 'src', 'icon', 'color', 'colour', 'cc', 'class', 'classes', 'slug', 'version', 'ver', 'action', 'mode',
		'tier', 'lang', 'locale', 'format', 'unit', 'currency', 'date', 'time', 'timestamp', 'ts', 'created', 'updated',
		'modified', 'key', 'method', 'route', 'cat', 'group', 'scope', 'engine', 'provider', 'tz', 'timezone', 'sort', 'order',
		'dir', 'direction', 'path', 'mime', 'ext', 'etag', 'checksum', 'signature', 'sig', 'uuid', 'guid', 'at', 'nonce_key' );
	/** Keys whose value is a secret, whatever it is. */
	const SECRET_KEYS = array( 'gate_code', 'gatecode', 'gate', 'access_code', 'lockbox', 'lockbox_code', 'alarm_code', 'password', 'pin' );
	/** Keys whose value is a WordPress login (a message's author, mentions, the other person in a direct message). */
	const LOGIN_KEYS = array( 'login', 'user_login', 'other_login', 'username', 'user_name' );
	/** Keys whose value is a person's initials (a salesperson code): a team member's become their pseudonym. */
	const INITIALS_KEYS = array( 'initials', 'user_initials', 'salesperson', 'sales_rep', 'rep', 'submitted_by', 'assigned_to', 'tech', 'sp_code', 'seller' );
	/** Phrases that read like a name but are not one (holidays, set expressions). Lower case. The business's own come
	 *  from its Identity Pack and the zdz_privacy_view_words filter. */
	const SAFE_PHRASES = array( 'will call', 'grant funding', 'martin luther king', 'presidents day', 'veterans day',
		'mother s day', 'father s day', 'new year', 'labor day', 'memorial day', 'black friday', 'el nino', 'el niño' );

	/** Courtesy titles: the capitalised word after one is a name. */
	const HONORIFICS = array( 'mr', 'mrs', 'ms', 'mx', 'dr', 'miss', 'mister', 'sir', 'madam', 'madame', 'senor', 'señor', 'senora', 'señora', 'sra', 'sr' );
	/** Months that are also first names: safe alone, part of a name next to one ("April Jordan"). */
	const MONTH_NAMES = array( 'april', 'may', 'june', 'august' );

	/** 3. Words that make a capitalised run safe (not a person). Lower case. Core's generic list: the platform's own
	 *  words, report labels, calendar, common systems and interface words. The business's names, its product words and
	 *  its places are added at run time (safe_words()). */
	const SAFE_WORDS = array(
		// the platform, its apps and its words
		'zorderz', 'company', 'app', 'apps', 'chat', 'charts', 'chart', 'hub', 'analytics', 'commission', 'calculator',
		'estimate', 'estimates', 'creator', 'jobs', 'job', 'projects', 'project', 'scheduler', 'surveys', 'survey', 'leads',
		'lead', 'doorhangers', 'doorhanger', 'territories', 'territory', 'prep', 'camera', 'receipt', 'messages', 'inbox',
		'hours', 'media', 'vault', 'knowledge', 'price', 'book', 'assistant', 'team', 'roles', 'route', 'planner', 'dot',
		'plot', 'agreements', 'agreement', 'invoice', 'invoices', 'ai', 'usage', 'debug', 'trace', 'figure', 'check', 'names',
		'privacy', 'view', 'staff', 'cust', 'customer', 'customers', 'owner', 'owners', 'admin',
		// work, in general
		'install', 'installs', 'installation', 'installer', 'installers', 'repair', 'repairs', 'service', 'services',
		'products', 'product', 'line', 'lines', 'item', 'items', 'all', 'other', 'others', 'custom', 'standard',
		// money and reporting labels
		'revenue', 'sales', 'sale', 'growth', 'grew', 'growing', 'grow', 'fastest', 'fast', 'slowest', 'slow', 'highest',
		'high', 'lowest', 'low', 'best', 'worst', 'strongest', 'weakest', 'leading', 'rising', 'falling', 'flat', 'busiest',
		'quietest', 'most', 'least', 'first', 'second', 'third', 'final', 'overview', 'details', 'detail', 'result', 'results',
		'figures', 'numbers', 'count', 'counts', 'share', 'percent', 'ratio', 'rate', 'prices', 'cost', 'costs', 'total',
		'totals', 'subtotal', 'grand', 'net', 'gross', 'average', 'avg', 'top', 'biggest', 'largest', 'smallest', 'ledger',
		'ledgers', 'key', 'takeaway', 'takeaways', 'bottom', 'year', 'years', 'over', 'month', 'months', 'quarter', 'quarters',
		'week', 'weeks', 'day', 'days', 'change', 'trend', 'summary', 'note', 'notes', 'next', 'steps', 'step', 'paid', 'owed',
		'due', 'overdue', 'open', 'closed', 'won', 'lost', 'new', 'old', 'last', 'prior', 'previous', 'same', 'peak', 'peaked',
		'source', 'sources', 'region', 'regions', 'area', 'areas', 'zip', 'zips', 'county', 'north', 'south', 'east', 'west',
		'central', 'coastal', 'inland', 'valley', 'answer', 'answered', 'instant', 'records', 'data', 'what',
		'why', 'how', 'where', 'who', 'which', 'when', 'this', 'that', 'these', 'those', 'the', 'and', 'for', 'from', 'with',
		'per', 'only', 'more', 'less', 'up', 'down', 'still', 'not', 'no', 'yes', 'is', 'are', 'was', 'a', 'an', 'of', 'in',
		'on', 'by', 'to', 'at', 'as', 'or',
		// channels and systems
		'google', 'ads', 'ad', 'facebook', 'instagram', 'yelp', 'freshbooks', 'nutshell', 'quickbooks', 'stripe', 'outlook',
		'microsoft', 'gmail', 'organic', 'referral', 'referrals', 'newspaper', 'website', 'web', 'email', 'phone', 'text',
		'call', 'calls', 'search', 'maps', 'business', 'profile', 'reviews', 'review', 'anthropic', 'claude', 'poe', 'gemini',
		// calendar
		'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november',
		'december', 'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept', 'oct', 'nov', 'dec', 'monday', 'tuesday',
		'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'spring', 'summer', 'fall', 'autumn', 'winter', 'season',
		'today', 'yesterday', 'tomorrow', 'morning', 'afternoon', 'evening', 'tonight', 'mon', 'tue', 'tues', 'wed', 'thu',
		'thur', 'thurs', 'fri', 'sat', 'sun', 'christmas', 'thanksgiving', 'easter', 'halloween', 'memorial', 'labor',
		// pseudonym words and this mode's own labels
		'inv', 'est', 'photo', 'hidden', 'private', 'sealed', 'gate', 'code',
		// acronyms of four letters or more (three or fewer in capitals are never names), tech and brands answers name
		'hvac', 'fema', 'osha', 'usps', 'ups', 'fedex', 'chatgpt', 'openai', 'iphone', 'ipad', 'android', 'linkedin',
		'youtube', 'tiktok', 'wordpress', 'vimeo', 'zoom', 'slack', 'excel', 'openstreetmap', 'leaflet', 'mapbox',
		'cloudflare', 'blinkmacsystemfont', 'segoe', 'roboto', 'helvetica', 'arial', 'inter', 'menlo', 'monaco', 'consolas',
		'courier', 'georgia', 'verdana', 'tahoma',
		// interface words, languages and countries
		'link', 'links', 'copy', 'paste', 'open', 'tap', 'click', 'save', 'cancel', 'close', 'reload', 'download', 'upload',
		'english', 'spanish', 'american', 'usa',
		// payments and retailers everyone names
		'venmo', 'zelle', 'paypal', 'costco', 'amazon',
	);

	private static $coder     = null;
	private static $memo      = array();
	private static $staff     = null;
	private static $safe      = null;
	private static $in_filter = false;
	private static $started   = false;
	private static $words     = null;
	private static $docs      = null;
	private static $places    = null;
	private static $products  = null;
	private static $vocab     = null;
	private static $view      = null;   // this reply's tag (4 letters, no vowels), made on first use
	private static $labels    = null;
	private static $depth     = 0;
	private static $own       = null;
	private static $kept      = array();   // what protect() set aside in this call: placeholder => original
	private static $held      = '';
	private static $dm_names  = null;    // the direct-message partner's name, kept readable where the account writes to them
	private static $by_code   = array();   // resolve_in()'s customer codes under one tag
	private static $early       = false;   // verdict() is being asked before WordPress has routed the request (the early gate)
	private static $early_login = '';      // the Privacy View login a sign-in cookie named, as the early gate found it
	private static $early_done  = false;
	private static $defer_ready = false;   // at plugins_loaded, first of all: the apps have loaded no class and declared nothing yet
	private static $stripped    = array(); // the cookie names taken away from this request (names only)
	private static $input       = null;    // tests put a request body here (input())

	/* ================================================================== who */

	/** Is Privacy View on for this user (the signed-in user by default)? Only an administrator sets it. */
	public static function is_on( $user_id = null ) {
		if ( null === $user_id && '' !== self::$early_login && ! function_exists( 'wp_get_current_user' ) ) {
			// asked before WordPress can say who is signed in (a plugin's file loading, after the early gate found a
			// sign-in cookie naming the account): yes -- never a quiet "no"
			return true;
		}
		$user_id = null === $user_id ? ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ) : (int) $user_id;
		return $user_id > 0 && '1' === (string) get_user_meta( $user_id, self::META, true );
	}

	/** Forget everything kept for this request (tests, and after a profile change). */
	public static function flush() {
		self::$memo     = array();
		self::$staff    = null;
		self::$safe     = null;
		self::$words    = null;
		self::$docs     = null;
		self::$places   = null;
		self::$products = null;
		self::$vocab    = null;
		self::$view     = null;
		self::$labels   = null;
		self::$own      = null;
		self::$kept     = array();
		self::$dm_names = null;
		self::$by_code  = array();
		self::$coder    = null;
	}

	/* ================================================================== boot */

	public static function boot() {
		// The gate runs as soon as the theme is loaded (after_setup_theme, first of all): before any plugin's init,
		// wp_loaded or admin_init handler. The later hooks are a second net. What a theme cannot be in front of is code
		// a plugin runs as its file loads or on plugins_loaded / setup_theme (a theme loads after those). The optional
		// must-use file (mu-plugin/0-zdz-privacy-view-gate.php, installed from Zorderz -> Privacy View) is in front of
		// those: it calls early_gate() below before any plugin loads.
		add_action( 'after_setup_theme', array( __CLASS__, 'gate' ), PHP_INT_MIN );
		add_action( 'init', array( __CLASS__, 'start' ), 0 );
		add_action( 'admin_init', array( __CLASS__, 'guard_admin' ), 0 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_rest' ), 0, 3 );
		add_action( 'admin_post', array( __CLASS__, 'refuse' ), 0 );
		add_action( 'admin_post_nopriv', array( __CLASS__, 'refuse' ), 0 );
		add_filter( 'heartbeat_received', array( __CLASS__, 'heartbeat' ), 0, 2 );
		add_filter( 'pre_comment_on_post', array( __CLASS__, 'refuse' ), 0 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ), 99 );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'profile_save' ) );
		// After the Zorderz menu itself (priority 10): a submenu added before its parent is filed under another name,
		// and WordPress then refuses it to every administrator.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'shutdown', array( __CLASS__, 'restart' ), 2 );
		// the account signs in only with its browser cookie: no application passwords, no XML-RPC
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'no_app_passwords' ), 99, 2 );
		add_filter( 'authenticate', array( __CLASS__, 'no_xmlrpc' ), 999, 1 );
		// the business's own safe words and phrases travel in its Identity Pack (knowledge.yml `privacy_view`)
		add_filter( 'zdz_identity_pack_consumers', array( __CLASS__, 'pack_consumer' ) );
		// what the apps declare on the filters, noted for the early gate (only while its file is installed)
		add_action( 'admin_init', array( __CLASS__, 'remember_declared' ), 99 );
	}

	/* ================================================================== the Identity Pack's part */

	/** Registers knowledge.yml's `privacy_view` block with the Identity Pack (preview, apply, snapshot, restore). */
	public static function pack_consumer( $consumers ) {
		$consumers = is_array( $consumers ) ? $consumers : array();
		$consumers['privacy-view'] = array(
			'label'    => __( 'Privacy View', 'zorderz' ),
			'files'    => array( 'knowledge' ),
			'paths'    => array( 'knowledge' => array( 'privacy_view' ) ),
			'preview'  => array( __CLASS__, 'pack_preview' ),
			'apply'    => array( __CLASS__, 'pack_apply' ),
			'snapshot' => array( __CLASS__, 'pack_snapshot' ),
			'restore'  => array( __CLASS__, 'pack_restore' ),
		);
		return $consumers;
	}

	/**
	 * The vocabulary a pack carries: knowledge.yml
	 *   privacy_view:
	 *     safe_words:   [ ... ]   one word each: the business's product, trade and place words
	 *     safe_phrases: [ ... ]   two words or more: brands, places, set expressions
	 * Each is cleaned as the run-time reader cleans it; anything else in the file is left for its own reader.
	 * null when the pack says nothing about it.
	 */
	public static function pack_vocabulary( array $files ) {
		$k = $files['knowledge'] ?? null;
		if ( ! is_array( $k ) || ! isset( $k['privacy_view'] ) || ! is_array( $k['privacy_view'] ) ) {
			return null;
		}
		$pv    = $k['privacy_view'];
		$words = array();
		foreach ( (array) ( $pv['safe_words'] ?? $pv['words'] ?? array() ) as $w ) {
			$w = mb_strtolower( trim( (string) $w ) );
			if ( preg_match( '/^[\p{L}][\p{L}\'’\-]{0,39}$/u', $w ) ) {
				$words[ $w ] = true;
			}
		}
		$phrases = array();
		foreach ( (array) ( $pv['safe_phrases'] ?? $pv['phrases'] ?? array() ) as $p ) {
			$p = trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( (string) $p ) ) );
			if ( '' !== $p && mb_strlen( $p ) <= 80 && false !== strpos( $p, ' ' ) ) {
				$phrases[ $p ] = true;
			}
		}
		ksort( $words );
		ksort( $phrases );
		return array( 'words' => array_keys( $words ), 'phrases' => array_keys( $phrases ) );
	}

	public static function pack_preview( array $files ) {
		$new = self::pack_vocabulary( $files );
		if ( null === $new ) {
			return array();
		}
		$old = get_option( self::VOCAB_OPT, array() );
		$old = is_array( $old ) ? $old : array();
		$out = array();
		foreach ( array( 'words', 'phrases' ) as $part ) {
			$was = array_values( (array) ( $old[ $part ] ?? array() ) );
			if ( $was !== $new[ $part ] ) {
				$out[] = array( 'file' => 'knowledge', 'path' => 'privacy_view.safe_' . $part, 'from' => count( $was ) . ' ' . $part, 'to' => count( $new[ $part ] ) . ' ' . $part );
			}
		}
		return $out;
	}

	public static function pack_apply( array $files, $slug = '' ) {
		$new = self::pack_vocabulary( $files );
		if ( null === $new ) {
			return array( 'knowledge: no privacy_view block, Privacy View vocabulary left as it was.' );
		}
		update_option( self::VOCAB_OPT, $new + array( 'pack' => (string) $slug ), false );
		self::flush();
		return array( sprintf( 'Privacy View vocabulary applied (%d words, %d phrases).', count( $new['words'] ), count( $new['phrases'] ) ) );
	}

	public static function pack_snapshot() {
		$v = get_option( self::VOCAB_OPT, null );
		return is_array( $v ) ? $v : null;
	}

	public static function pack_restore( $kept ) {
		if ( is_array( $kept ) ) {
			update_option( self::VOCAB_OPT, $kept, false );
		} else {
			delete_option( self::VOCAB_OPT );
		}
		self::flush();
	}

	public static function no_app_passwords( $available, $user = null ) {
		return is_object( $user ) && isset( $user->ID ) && self::is_on( (int) $user->ID ) ? false : $available;
	}

	public static function no_xmlrpc( $user ) {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && is_object( $user ) && isset( $user->ID ) && self::is_on( (int) $user->ID ) ) {
			return new WP_Error( 'zdz_privacy_view', __( 'Not available in Privacy View.', 'zorderz' ) );
		}
		return $user;
	}

	/** WordPress flushes every buffer at shutdown (priority 1); anything printed after that is filtered too. */
	public static function restart() {
		if ( self::$started && self::is_on() && 0 === ob_get_level() ) {
			self::$held = '';
			ob_start( array( __CLASS__, 'filter_output' ), self::CHUNK );
		}
	}

	/** For the account, every byte this request sends passes filter_output() (the second net). Started once. */
	public static function start() {
		if ( ! self::$started && self::is_on() ) {
			self::$started = true;
			ob_start( array( __CLASS__, 'filter_output' ), self::CHUNK );   // chunks are held by the filter, not by PHP
		}
	}

	/* ================================================================== 1. deny by default */

	/** The direct-message actions: send from the chat, and in Messages the list, the thread, new messages, mark-read
	 *  and a text post. The messaging app and the chat narrow each to that one thread and that one person. */
	const DM_AJAX = array( 'zana_confirm_zim_dm', 'zim_sidebar', 'zim_fetch_before', 'zim_poll', 'zim_mark_read', 'zim_post' );

	/** Does the messaging app narrow every direct-message action to the account's one thread? Only then are they open. */
	public static function dm_narrowed() {
		return class_exists( 'ZIM_Privacy_View' ) && method_exists( 'ZIM_Privacy_View', 'action_ok' ) && method_exists( 'ZIM_Privacy_View', 'conversation_ok' );
	}

	/* ---- what the apps declare ---------------------------------------------------------------------------------- */

	/**
	 * Is the request being judged before the apps have had their say? True at the two looks the early gate takes: before
	 * any plugin has loaded, and at plugins_loaded first of all (an app may load its classes and add its filters later
	 * on that same hook). There the apps' declarations are read from the note kept of them (DECLARED_OPT), and what only
	 * an app can answer -- is the messaging app one that narrows to the one thread? -- is left to the gate proper.
	 */
	private static function before_apps() {
		return self::$early || self::$defer_ready;
	}

	/**
	 * A declaration held to its shape, whatever a filter returned or the stored note holds. Anything else is dropped:
	 *   ajax     [ action => [ field, ... ] ]  lower-case action names Core does not list itself, each with the field
	 *            names its requests may carry besides "action" and "nonce" (plain names; none declared, none allowed)
	 *   rest     routes the account may read (GET or HEAD only; rest_allowed() never lets a write or a sign-in route by)
	 *   signin   sign-in routes a signed-out browser uses (let by before WordPress can say who is signed in)
	 *   headers  starts of $_SERVER names of request headers that refuse a request (never one every browser sends)
	 *   login    query keys wp-login.php may carry besides WordPress's own (never a sign-in form's field)
	 * Sorted, so two equal declarations compare equal.
	 */
	public static function clean_declared( $d ) {
		$d    = is_array( $d ) ? $d : array();
		$out  = array( 'ajax' => array(), 'rest' => array(), 'signin' => array(), 'headers' => array(), 'login' => array() );
		$core = array_merge( self::ALLOWED_AJAX, self::DM_AJAX );
		foreach ( is_array( $d['ajax'] ?? null ) ? $d['ajax'] : array() as $action => $fields ) {
			if ( ! is_string( $action ) || ! preg_match( '/\A[a-z0-9_\-]{1,80}\z/', $action ) || in_array( $action, $core, true ) || count( $out['ajax'] ) >= 200 ) {
				continue;   // Core's own actions keep Core's rows
			}
			$row = array();
			foreach ( is_array( $fields ) ? $fields : array() as $f ) {
				if ( is_string( $f ) && preg_match( '/\A[A-Za-z0-9_\-]{1,64}\z/', $f ) && ! in_array( $f, self::AJAX_BASE_KEYS, true ) && count( $row ) < 40 ) {
					$row[ $f ] = true;
				}
			}
			ksort( $row );
			$out['ajax'][ $action ] = array_map( 'strval', array_keys( $row ) );
		}
		ksort( $out['ajax'] );
		foreach ( array( 'rest', 'signin' ) as $part ) {
			foreach ( is_array( $d[ $part ] ?? null ) ? $d[ $part ] : array() as $route ) {
				if ( is_string( $route ) && preg_match( '#\A/[A-Za-z0-9_\-/.]{1,160}\z#', $route ) && ! self::odd_path( $route ) && count( $out[ $part ] ) < 100 ) {
					$out[ $part ][ '/' . trim( $route, '/' ) ] = true;
				}
			}
		}
		foreach ( is_array( $d['headers'] ?? null ) ? $d['headers'] : array() as $h ) {
			$h = is_string( $h ) ? strtoupper( $h ) : '';
			if ( ! preg_match( '/\AHTTP_[A-Z0-9_]{3,60}\z/', $h ) || count( $out['headers'] ) >= 40 ) {
				continue;
			}
			foreach ( array( 'HTTP_HOST', 'HTTP_USER_AGENT', 'HTTP_ACCEPT', 'HTTP_COOKIE', 'HTTP_CONTENT_TYPE', 'HTTP_CONTENT_LENGTH', 'HTTP_REFERER', 'HTTP_X_WP_NONCE', 'HTTP_X_REQUESTED_WITH' ) as $every ) {
				if ( 0 === strpos( $every, $h ) ) {
					continue 2;   // it would refuse every request the account's own pages make
				}
			}
			$out['headers'][ $h ] = true;
		}
		foreach ( is_array( $d['login'] ?? null ) ? $d['login'] : array() as $k ) {
			if ( is_string( $k ) && preg_match( '/\A[A-Za-z0-9_\-]{1,40}\z/', $k ) && ! in_array( strtolower( $k ), self::WPLOGIN_NEVER, true ) && count( $out['login'] ) < 20 ) {
				$out['login'][ $k ] = true;
			}
		}
		foreach ( array( 'rest', 'signin', 'headers', 'login' ) as $part ) {
			ksort( $out[ $part ] );
			$out[ $part ] = array_map( 'strval', array_keys( $out[ $part ] ) );
		}
		return $out;
	}

	/**
	 * What the site's apps and plugins declare right now, on the filters (each is handed Core's own list and returns
	 * the list; what it adds is added, and Core's own entries stay Core's):
	 *   zdz_privacy_view_allowed_ajax     action names -- an app adds a read of its own once it masks its own replies
	 *                                     and has tests
	 *   zdz_privacy_view_ajax_keys        [ action => [ field names ] ] -- the fields each added action's requests carry
	 *   zdz_privacy_view_allowed_rest     REST routes it may read
	 *   zdz_privacy_view_signin_rest      sign-in routes (never opened to the account itself)
	 *   zdz_privacy_view_trigger_headers  headers this site's host or page cache acts on
	 *   zdz_privacy_view_login_keys       a host's own marker on wp-login.php addresses
	 * An app adds its filters as its file loads or on plugins_loaded: the gate judges at after_setup_theme, first of all.
	 */
	public static function declared_now() {
		$ask = function ( $hook, $core ) {
			$v = function_exists( 'apply_filters' ) ? apply_filters( $hook, $core ) : $core;
			return is_array( $v ) ? $v : $core;   // a filter that returns nonsense changes nothing
		};
		$core = array_merge( self::ALLOWED_AJAX, self::DM_AJAX );
		$rows = $ask( 'zdz_privacy_view_ajax_keys', self::AJAX_KEYS );
		$ajax = array();
		foreach ( $ask( 'zdz_privacy_view_allowed_ajax', self::ALLOWED_AJAX ) as $a ) {
			if ( is_string( $a ) && ! in_array( $a, $core, true ) ) {
				$ajax[ $a ] = isset( $rows[ $a ] ) && is_array( $rows[ $a ] ) ? $rows[ $a ] : array();
			}
		}
		// what each filter added to the list it was handed (Core's own entries are Core's, whatever came back)
		$added = function ( $hook, array $mine ) use ( $ask ) {
			return array_filter( $ask( $hook, $mine ), function ( $v ) use ( $mine ) {
				return is_string( $v ) && ! in_array( $v, $mine, true );
			} );
		};
		return self::clean_declared( array(
			'ajax'    => $ajax,
			'rest'    => $added( 'zdz_privacy_view_allowed_rest', self::allowed_rest() ),
			'signin'  => $added( 'zdz_privacy_view_signin_rest', self::core_signin_rest() ),
			'headers' => $added( 'zdz_privacy_view_trigger_headers', self::TRIGGER_HEADERS ),
			'login'   => $added( 'zdz_privacy_view_login_keys', self::WPLOGIN_KEYS ),
		) );
	}

	/** The note kept of those declarations (see DECLARED_OPT), held to its shape again as it is read. */
	private static function declared_kept() {
		return self::clean_declared( function_exists( 'get_option' ) ? get_option( self::DECLARED_OPT, array() ) : array() );
	}

	private static function declared() {
		return self::before_apps() ? self::declared_kept() : self::declared_now();
	}

	/**
	 * Keeps that note up to date: only while the early gate's file is installed (it is the note's one reader), only from
	 * an administrator's own wp-admin request, never from an account in Privacy View, and only when something changed.
	 * $force: the file is being installed in this very request. Without the file a note left behind (the file was
	 * deleted by hand) is taken away. Returns whether the note was written.
	 */
	public static function remember_declared( $force = false ) {
		if ( self::is_on() || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) || ! function_exists( 'update_option' ) ) {
			return false;
		}
		if ( true !== $force && ! defined( 'ZDZ_PV_GATE_VERSION' ) ) {
			if ( null !== get_option( self::DECLARED_OPT, null ) ) {
				self::forget_declared();   // nobody reads it now
			}
			return false;
		}
		$now = self::declared_now();
		if ( get_option( self::DECLARED_OPT, null ) === $now ) {
			return false;
		}
		update_option( self::DECLARED_OPT, $now, true );
		return true;
	}

	/** The early gate's file was removed: its note goes with it. */
	public static function forget_declared() {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::DECLARED_OPT );
		}
	}

	/* ---- the lists, asked ---------------------------------------------------------------------------------------- */

	public static function ajax_allowed( $action ) {
		$action = strtolower( (string) $action );
		if ( '' === $action ) {
			return false;
		}
		if ( in_array( $action, self::DM_AJAX, true ) ) {
			// only once an administrator has chosen who it may message, and only with a messaging app that narrows each
			// action to that one thread -- never with one that does not. Before any plugin has loaded there is no
			// messaging app to ask; at plugins_loaded, first of all, its class is not loaded yet, but whether anyone
			// was chosen is already known and is asked there. The gate proper asks both.
			return self::$early || ( self::dm_partner() > 0 && ( self::$defer_ready || self::dm_narrowed() ) );
		}
		if ( self::before_apps() ) {
			// no app has loaded to declare its own: Core's list, and what the apps declared when last asked. The gate
			// proper asks the apps themselves, so an action whose app has gone is refused there.
			return in_array( $action, self::ALLOWED_AJAX, true ) || isset( self::declared_kept()['ajax'][ $action ] );
		}
		$list = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_privacy_view_allowed_ajax', self::ALLOWED_AJAX ) : self::ALLOWED_AJAX;
		if ( ! is_array( $list ) || ! in_array( $action, $list, true ) ) {
			return false;   // (a filter may take one of Core's away)
		}
		return in_array( $action, self::ALLOWED_AJAX, true ) || isset( self::declared_now()['ajax'][ $action ] );
	}

	/**
	 * The only field names an allowed action may carry: "action", "nonce" and its own row. Core's rows are Core's: no
	 * filter widens one. An action an app added names its fields on zdz_privacy_view_ajax_keys; none named, none allowed.
	 */
	public static function ajax_keys( $action ) {
		$action = strtolower( (string) $action );
		if ( array_key_exists( $action, self::AJAX_KEYS ) ) {
			return array_merge( self::AJAX_BASE_KEYS, self::AJAX_KEYS[ $action ] );
		}
		$d = self::declared();
		return array_merge( self::AJAX_BASE_KEYS, $d['ajax'][ $action ] ?? array() );
	}

	private static function rest_ns() {
		return '/' . trim( defined( 'ZDZ_REST_NS' ) ? (string) ZDZ_REST_NS : 'zorderz/v1', '/' );
	}

	/** The only REST routes (GET) the account may call: the unread count and its own usage-records consent. */
	public static function allowed_rest() {
		$ns = self::rest_ns();
		return array( $ns . '/notifications/unread-count', $ns . '/consent' );
	}

	private static function core_signin_rest() {
		$ns = self::rest_ns();
		return array_map( function ( $r ) use ( $ns ) {
			return $ns . '/' . $r;
		}, self::SIGNIN_ROUTES );
	}

	/** The sign-in routes (SIGNIN_ROUTES under the REST namespace, and any the site's sign-in code declares). */
	public static function signin_rest() {
		return array_values( array_unique( array_merge( self::core_signin_rest(), self::declared()['signin'] ) ) );
	}

	public static function rest_allowed( $route, $method ) {
		if ( ! in_array( strtoupper( (string) $method ), array( 'GET', 'HEAD' ), true ) ) {
			return false;   // reads only, whatever a filter lists
		}
		$route = '/' . trim( (string) $route, '/' );
		if ( in_array( $route, self::signin_rest(), true ) ) {
			return false;   // the account itself gets no sign-in route, whatever a filter lists
		}
		if ( self::before_apps() ) {
			return in_array( $route, array_merge( self::allowed_rest(), self::declared_kept()['rest'] ), true );
		}
		$list = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_privacy_view_allowed_rest', self::allowed_rest() ) : self::allowed_rest();
		return is_array( $list ) && in_array( $route, $list, true );
	}

	/**
	 * May the app shell list this app for the account, and may the account open it? Core's two, and an app that says
	 * on zdz_privacy_view_allowed_apps that it is one made safe for the account: it masks its own replies where the
	 * data is, refuses every request of its own but its listed reads, and declares those reads on the two AJAX filters.
	 * An app that does not say so is not listed, and each of its requests is refused.
	 */
	public static function app_allowed( $app_id ) {
		$app_id = is_string( $app_id ) ? $app_id : '';
		if ( '' === $app_id ) {
			return false;
		}
		$list = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_privacy_view_allowed_apps', self::ALLOWED_APPS ) : self::ALLOWED_APPS;
		return is_array( $list ) && in_array( $app_id, $list, true );
	}

	/** The starts of the $_SERVER names of the request headers that refuse a request: Core's, and this site's own. */
	public static function trigger_headers() {
		return array_values( array_unique( array_merge( self::TRIGGER_HEADERS, self::declared()['headers'] ) ) );
	}

	/** The only query keys wp-login.php may carry for the account: WordPress's own, and a host's marker if one is declared. */
	public static function login_keys() {
		return array_values( array_unique( array_merge( self::WPLOGIN_KEYS, self::declared()['login'] ) ) );
	}

	/** A wp-admin screen, with the query keys it carries (an unexpected key, e.g. a View As switch, refuses it). */
	public static function screen_allowed( $script, $page = '', array $keys = array() ) {
		$key = '' !== (string) $page ? $script . '?page=' . $page : (string) $script;
		if ( ! array_key_exists( $key, self::ALLOWED_SCREENS ) ) {
			return false;
		}
		return ! array_diff( $keys, self::ALLOWED_SCREENS[ $key ] );
	}

	/** A front-end request: the app shell at the home address, or a photo; GET only; only the listed query keys. */
	public static function front_allowed( $path, array $keys, $home_path = '/' ) {
		$path = '/' . trim( (string) $path, '/' );
		$home = '/' . trim( (string) $home_path, '/' );
		if ( $path !== $home && $path !== rtrim( $home, '/' ) . '/index.php' ) {
			return false;   // only the home address, exactly as written
		}
		if ( in_array( 'zdz_media', $keys, true ) ) {
			return ! array_diff( $keys, self::FRONT_KEYS['zdz_media'] );   // the photo server sends a placeholder
		}
		if ( in_array( 'zim_page', $keys, true ) ) {
			// its direct-message thread, docked under a chat answer (the messaging app opens only that thread for it),
			// never the full messaging page; asked of the messaging app as the direct-message actions are (ajax_allowed())
			return ( self::$early || ( self::dm_partner() > 0 && ( self::$defer_ready || self::dm_narrowed() ) ) )
				&& ! array_diff( self::DM_PAGE_KEYS, $keys ) && ! array_diff( $keys, array_merge( self::DM_PAGE_KEYS, array( 'cb' ) ) );
		}
		return ! array_diff( $keys, self::FRONT_KEYS[''] );
	}

	/* ---- the request, read --------------------------------------------------------------------------------------- */

	/** What kind of request this is, from the request alone (before WordPress routes it). */
	public static function request_kind() {
		$pagenow = self::pagenow();
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return 'ajax';
		}
		// wp-login.php, wp-admin and XML-RPC are decided BEFORE REST: a wp-admin screen written with
		// ?rest_route=<a listed route> (or with /wp-json/ somewhere in its address) must never be read as that REST call
		if ( 'wp-login.php' === (string) $pagenow ) {
			return 'login';
		}
		if ( ( defined( 'WP_ADMIN' ) && WP_ADMIN ) || ( function_exists( 'is_admin' ) && is_admin() ) ) {
			return 'admin';
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( self::is_rest_request() ) {
			return 'rest';
		}
		return 'front';
	}

	/** The real request method. A method override (?_method=, X-HTTP-Method-Override, a POSTed _method) that differs
	 *  from it is refused outright (verdict), never read as what it asks for. */
	private static function method() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- compared with fixed method names only
		return strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
	}

	/**
	 * The script WordPress is running ($pagenow). WordPress sets it in wp-includes/vars.php, after the must-use plugins
	 * load; until then it is worked out here the same way, from PHP_SELF (vars.php's own rules).
	 */
	public static function pagenow() {
		global $pagenow;
		if ( ! self::$early && isset( $pagenow ) && '' !== (string) $pagenow ) {
			return (string) $pagenow;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- matched against fixed patterns to name the script, as WordPress's own vars.php does
		$self = (string) ( $_SERVER['PHP_SELF'] ?? '' );
		if ( function_exists( 'is_admin' ) ? is_admin() : ( defined( 'WP_ADMIN' ) && WP_ADMIN ) ) {   // as vars.php asks it
			if ( defined( 'WP_NETWORK_ADMIN' ) && WP_NETWORK_ADMIN ) {
				preg_match( '#/wp-admin/network/?(.*?)$#i', $self, $m );
			} elseif ( defined( 'WP_USER_ADMIN' ) && WP_USER_ADMIN ) {
				preg_match( '#/wp-admin/user/?(.*?)$#i', $self, $m );
			} else {
				preg_match( '#/wp-admin/?(.*?)$#i', $self, $m );
			}
			$now = preg_replace( '#\?.*?$#', '', trim( (string) ( $m[1] ?? '' ), '/' ) );
			if ( '' === $now || 'index' === $now || 'index.php' === $now ) {
				return 'index.php';
			}
			preg_match( '#(.*?)(/|$)#', $now, $m );
			$now = strtolower( (string) $m[1] );
			return '.php' === substr( $now, -4 ) ? $now : $now . '.php';
		}
		return preg_match( '#([^/]+\.php)([?/].*?)?$#i', $self, $m ) ? strtolower( $m[1] ) : 'index.php';
	}

	/** The action WordPress will run: $_REQUEST's, which WordPress itself rebuilds from $_GET and $_POST after
	 *  plugins_loaded. Before that, worked out the same way: $_POST's, else $_GET's (PHP's own $_REQUEST may hold a cookie). */
	private static function asked() {
		if ( self::$early ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- the action's name is compared with the lists to decide whether to refuse the request; nothing is run or stored here
			return $_POST['action'] ?? ( $_GET['action'] ?? null );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- the action's name is compared with the lists to decide whether to refuse the request; nothing is run or stored here
		return $_REQUEST['action'] ?? null;
	}

	/** A request header some code acts on (trigger_headers()), by its $_SERVER name; '' if the request has none. */
	private static function trigger_header() {
		$triggers = self::trigger_headers();
		foreach ( array_keys( (array) $_SERVER ) as $k ) {
			foreach ( $triggers as $t ) {
				if ( is_string( $k ) && 0 === strpos( strtoupper( $k ), $t ) ) {
					return strtoupper( $k );
				}
			}
		}
		return '';
	}

	/**
	 * Takes every cookie but COOKIE_KEEP's out of the request: from $_COOKIE, from the Cookie header
	 * ($_SERVER['HTTP_COOKIE'], kept piece by piece as written), and from $_REQUEST where PHP put cookies in it. Returns
	 * the names taken (names only, never a value). Called only for the account.
	 */
	public static function keep_cookies() {
		$keep = function ( $name ) {
			if ( ! is_string( $name ) && ! is_int( $name ) ) {
				return false;
			}
			$name = (string) $name;
			foreach ( self::COOKIE_KEEP as $re ) {
				if ( preg_match( $re, $name ) ) {
					return true;
				}
			}
			foreach ( array( 'LOGGED_IN_COOKIE', 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE', 'TEST_COOKIE' ) as $c ) {
				if ( defined( $c ) && constant( $c ) === $name ) {
					return true;   // a name wp-config.php gave one of WordPress's own cookies
				}
			}
			return false;
		};
		$gone = array();
		foreach ( array_keys( (array) $_COOKIE ) as $name ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- $name is one of $_COOKIE's own keys (the loop above); only the value's type is read
			if ( ! $keep( $name ) || ! is_string( $_COOKIE[ $name ] ) ) {   // a cookie written as a list is no sign-in cookie
				$gone[] = (string) $name;
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only whether a name is present: a cookie's copy is taken out of $_REQUEST, nothing is acted on
				if ( isset( $_REQUEST[ $name ] ) && ! isset( $_GET[ $name ] ) && ! isset( $_POST[ $name ] ) ) {
					unset( $_REQUEST[ $name ] );
				}
				unset( $_COOKIE[ $name ] );
			}
		}
		if ( isset( $_SERVER['HTTP_COOKIE'] ) ) {
			$kept = array();
			$seen = array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- the header is rewritten keeping whole pieces of itself; nothing of it is stored, echoed or logged
			foreach ( explode( ';', (string) $_SERVER['HTTP_COOKIE'] ) as $piece ) {
				$piece = ltrim( $piece, " \t" );
				$eq    = strpos( $piece, '=' );
				// the name as PHP keys it: not %-decoded, "." and " " as "_"; the first of two is the one PHP keeps
				$name  = strtr( false === $eq ? $piece : substr( $piece, 0, $eq ), array( '.' => '_', ' ' => '_' ) );
				// a value as setcookie() writes it (no "," that a looser reader could split on)
				$plain = false !== $eq && preg_match( '/\A[A-Za-z0-9%._~+\-]*\z/', rtrim( substr( $piece, $eq + 1 ) ) );
				if ( '' !== $piece && $plain && ! isset( $seen[ $name ] ) && isset( $_COOKIE[ $name ] ) && $keep( $name ) ) {
					$kept[]        = $piece;
					$seen[ $name ] = true;
				} elseif ( '' !== trim( $piece ) && ! isset( $seen[ $name ] ) && ! in_array( $name, $gone, true ) ) {
					$gone[] = $name;
				}
			}
			$_SERVER['HTTP_COOKIE'] = implode( '; ', $kept );
		}
		self::$stripped = array_values( array_unique( array_merge( self::$stripped, $gone ) ) );
		return $gone;
	}

	private static function overridden() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- compared with the real method; a difference refuses the request
		$o = $_GET['_method'] ?? ( $_POST['_method'] ?? ( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '' ) );
		if ( ! is_string( $o ) ) {
			return true;   // a list where a word belongs is not a method
		}
		$o = strtoupper( $o );
		return '' !== $o && $o !== self::method();
	}

	/** The path exactly as sent (no parse_url: "//jobs" has no path to it, and WordPress still routes it). */
	private static function raw_path() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- the address is only split, matched and compared; the log line made from it is cut to plain characters (plain_line())
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$cut = strcspn( $uri, '?#' );
		return substr( $uri, 0, $cut );
	}

	/** A path written oddly (doubled or back slashes, dot segments, encoded slashes or dots): refused, not normalised. */
	private static function odd_path( $path ) {
		return '' === $path || '/' !== $path[0] || false !== strpos( $path, '//' ) || false !== strpos( $path, '\\' )
			|| preg_match( '#/\.\.?(?:/|$)|%2f|%5c|%2e|%00#i', $path ) || preg_match( '/[\x00-\x20\x7f]/', $path );
	}

	private static function query_keys() {
		return array_map( 'strval', array_keys( (array) $_GET ) );
	}

	/** Every field name the request carries, in its address and in its body, as PHP (and so every handler) reads them. */
	private static function field_names() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- field names only, compared with the action's own list; the gate acts on nothing in the request
		return array_values( array_unique( array_map( 'strval', array_merge( array_keys( (array) $_GET ), array_keys( (array) $_POST ) ) ) ) );
	}

	/**
	 * The address's query exactly as it was sent: name=value pairs of plain characters, each name once, and the same
	 * names PHP read into $_GET. Some handlers search the raw address for their own word (a sign-in return looks for
	 * "code=", a wall lets "/.well-known/" by), and PHP's reading of an address is not its text: it drops a pair that
	 * has no name, keeps the last of two, and turns %xx and + back into characters. So the text is held to what was
	 * read. $slash: a REST route written as ?rest_route= has slashes in it. $encoded: wp-login.php's redirect_to is an
	 * address written as %xx (so no "=", "&" or "/" of its own is ever in the text).
	 */
	private static function plain_query( $slash = false, $encoded = false ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- the address is only split and matched against fixed patterns
		$uri   = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$at    = strpos( $uri, '?' );
		$raw   = false === $at ? '' : substr( $uri, $at + 1 );
		$names = array();
		if ( '' !== $raw ) {
			foreach ( explode( '&', $raw ) as $pair ) {
				$value = $encoded ? '[A-Za-z0-9_.\-%]*' : ( $slash ? '[A-Za-z0-9_.\-/]*' : '[A-Za-z0-9_.\-]*' );
				if ( ! preg_match( '#\A([A-Za-z0-9_\-]+)=(' . $value . ')\z#', $pair, $m ) || isset( $names[ $m[1] ] ) ) {
					return false;
				}
				$names[ $m[1] ] = true;
			}
		}
		$read = self::query_keys();
		$sent = array_map( 'strval', array_keys( $names ) );
		sort( $read );
		sort( $sent );
		return $read === $sent;
	}

	/**
	 * The start of the request's body as it was sent (what a handler would get from php://input), at most $max bytes:
	 * the gate never holds a large body in memory. Reading it here takes nothing from a later reader.
	 */
	private static function input( $max ) {
		if ( null !== self::$input ) {
			return (string) substr( (string) self::$input, 0, $max );
		}
		$raw = @file_get_contents( 'php://input', false, null, 0, $max );   // empty for a form with parts (PHP has read it)
		return false === $raw ? '' : (string) $raw;
	}

	/** The body's type as PHP itself reads it: the word before any ";", "," or space, in small letters. */
	private static function body_type() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- cut to its first word and compared with two fixed types
		$type = strtolower( trim( (string) ( $_SERVER['CONTENT_TYPE'] ?? ( $_SERVER['HTTP_CONTENT_TYPE'] ?? '' ) ) ) );
		return (string) substr( $type, 0, strcspn( $type, ";, \t" ) );
	}

	/**
	 * Is there a body that PHP did not turn, whole, into the fields of $_POST? A handler could read such a body for
	 * itself (php://input) beside an allowed request. So:
	 * - only a POST has a body at all: on anything else, nothing may have been sent;
	 * - a POST's type is exactly one of the two forms (not a word that begins like one), or there is no body;
	 * - a form with parts must have been read by PHP completely (nothing is left of it; no missing boundary);
	 * - an ordinary form's text is name=value pairs, every one with a name PHP keeps, a plain name once, and those
	 *   names are $_POST's (PHP drops a pair whose name begins with "[", and everything when the body is over its
	 *   size limit); and it is short (FORM_MAX).
	 */
	private static function unread_body() {
		$type = self::body_type();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read as a number
		$long = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- only whether the header is there
		$some = $long > 0 || '' !== (string) ( $_SERVER['HTTP_TRANSFER_ENCODING'] ?? '' );
		if ( 'POST' !== self::method() ) {
			return $some || '' !== self::input( 1 );
		}
		if ( 'multipart/form-data' === $type ) {
			return '' !== self::input( 1 );
		}
		if ( 'application/x-www-form-urlencoded' !== $type ) {
			return '' !== $type || $some || '' !== self::input( 1 );
		}
		if ( $long > self::FORM_MAX ) {
			return true;   // far beyond anything its scripts send; not read
		}
		$body = self::input( self::FORM_MAX + 1 );
		if ( strlen( $body ) > self::FORM_MAX ) {
			return true;
		}
		$names = array();
		foreach ( '' === $body ? array() : explode( '&', $body ) as $pair ) {
			$eq = strpos( $pair, '=' );
			if ( false === $eq || 0 === $eq ) {
				return true;   // no name, or no "=": not a field
			}
			$name = urldecode( substr( $pair, 0, $eq ) );
			if ( ! preg_match( '/\A[A-Za-z0-9_\-]+(?:\[[A-Za-z0-9_\-]*\])*\z/', $name ) ) {
				return true;   // a name PHP would drop or rewrite
			}
			$top = (string) substr( $name, 0, strcspn( $name, '[' ) );
			if ( $top === $name && isset( $names[ $top ] ) ) {
				return true;   // a plain name twice (PHP keeps the last; a handler reading the text might take the first)
			}
			$names[ $top ] = true;
		}
		$sent = array_map( 'strval', array_keys( $names ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- field names only, compared with the names written in the body
		$read = array_map( 'strval', array_keys( (array) $_POST ) );
		sort( $sent );
		sort( $read );
		return $sent !== $read;
	}

	/**
	 * An allowed AJAX action, read as a whole. '' if it is exactly what its script sends, else why not.
	 * - it came to admin-ajax.php itself: by POST with every field in the body and none in the address, or (AJAX_GET
	 *   alone) by GET with every field in the address and no body;
	 * - its action is written once and is the name WordPress will run (not a cookie's, not the name with something
	 *   around it);
	 * - it carries no field but its own (ajax_keys()), no file at all, and no body PHP did not read.
	 */
	private static function ajax_shape( $action, $path, $method ) {
		if ( self::odd_path( $path ) || ! preg_match( '#/wp-admin/admin-ajax\.php\z#', $path ) ) {
			return 'ajax-path:' . $path;
		}
		$by_get = in_array( $action, self::AJAX_GET, true );
		if ( 'POST' !== $method && ! ( 'GET' === $method && $by_get ) ) {
			return 'ajax-method:' . $method;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only whether any field came in the wrong place
		if ( 'POST' === $method ? ! empty( $_GET ) : ! empty( $_POST ) ) {
			return 'ajax-method:' . $method . ' both';
		}
		$asked = self::asked();   // what WordPress runs
		$seen  = 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- compared with the action WordPress will run; nothing is run or stored here
		foreach ( array( $_GET['action'] ?? null, $_POST['action'] ?? null ) as $one ) {
			if ( null === $one ) {
				continue;
			}
			if ( ! is_string( $one ) || $one !== $asked ) {
				return 'ajax-action:two';
			}
			$seen++;
		}
		if ( 0 === $seen || ! is_string( $asked ) || $asked !== $action ) {
			return 'ajax-action:' . $action;
		}
		$may = self::ajax_keys( $action );
		foreach ( self::field_names() as $name ) {
			if ( ! in_array( $name, $may, true ) ) {
				return 'ajax-field:' . $action . ':' . $name;
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- names only: any upload refuses the request
		foreach ( array_keys( (array) $_FILES ) as $name ) {
			return 'ajax-file:' . $action . ':' . $name;   // the account uploads nothing
		}
		if ( self::unread_body() ) {
			return 'ajax-body:' . $action;
		}
		return '';
	}

	/** What a refusal may say back: one word from a fixed list, never anything the request itself wrote. */
	public static function why_word( $why ) {
		foreach ( array( 'ajax-field' => 'field', 'ajax-file' => 'file', 'ajax-body' => 'body', 'ajax-action' => 'action', 'ajax-path' => 'address',
			'ajax-method' => 'method', 'rest-field' => 'field', 'login-field' => 'field', 'body' => 'body', 'query' => 'address', 'header' => 'header' ) as $start => $word ) {
			if ( is_string( $why ) && 0 === strpos( $why, $start . ':' ) ) {
				return $word;
			}
		}
		return 'not listed';
	}

	/** A reason as one short line of plain characters for the site's log, whatever the request wrote. */
	private static function plain_line( $why ) {
		return (string) preg_replace( '/[^A-Za-z0-9_.:\/ \-]/', '?', substr( is_string( $why ) ? $why : '', 0, 120 ) );
	}

	/**
	 * THE GATE (deny by default). Everything not listed is refused here, before WordPress or any plugin's init handler
	 * acts on it. Returns the verdict for tests: '' allowed, or the reason it was refused.
	 */
	public static function verdict() {
		$kind   = self::request_kind();
		$method = self::method();
		$keys   = self::query_keys();
		if ( self::overridden() ) {
			return 'override';
		}
		$header = self::trigger_header();
		if ( '' !== $header ) {
			return 'header:' . $header;
		}
		$path = self::raw_path();
		if ( 'ajax' !== $kind && 'login' !== $kind && self::odd_path( $path ) ) {
			return 'path:' . $path;
		}
		if ( 'login' !== $kind && ! self::plain_query( 'rest' === $kind ) ) {   // (wp-login.php: below, with a wider alphabet)
			return 'query:' . $kind;   // the address says only what its listed keys say
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only whether a body's fields came at all
		if ( ! in_array( $kind, array( 'ajax', 'login' ), true ) && ( ! empty( $_POST ) || ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) ) {
			return $kind . ':' . $method;   // WordPress reads query vars from a POST body too: none may arrive
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only whether a file came at all
		if ( ! in_array( $kind, array( 'ajax', 'login' ), true ) && ( ! empty( $_FILES ) || self::unread_body() ) ) {
			return 'body:' . $kind;   // nor any body at all, which a handler could read for itself
		}
		switch ( $kind ) {
			case 'ajax':
				$asked  = self::asked();
				$action = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', is_string( $asked ) ? $asked : '' ) );
				if ( ! self::ajax_allowed( $action ) ) {
					return 'ajax:' . $action;
				}
				return self::ajax_shape( $action, $path, $method );   // the whole request, not its name
			case 'rest':
				$route = self::rest_route( $path );
				if ( null === $route ) {
					return 'rest:odd';
				}
				if ( ! self::rest_allowed( $route, $method ) ) {
					return 'rest:' . $method . ' ' . $route;
				}
				$extra = array_diff( $keys, self::REST_KEYS );   // init runs for a REST call too
				return $extra ? 'rest-field:' . reset( $extra ) : '';
			case 'login':
				// wp-login.php runs init too, so it is held like everything else: by GET, with no body, its address as
				// PHP read it, and only its listed fields (signing out and the pages around it)
				$asked = self::asked();
				$act   = null === $asked ? '' : ( is_string( $asked ) ? $asked : '?' );
				if ( ! in_array( $act, array( '', 'login', 'logout', 'loggedout' ), true ) || ( isset( $_GET['action'] ) ? $_GET['action'] !== $act : '' !== $act ) ) {
					return 'login:' . $act;
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only whether a body or a file came at all
				if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || ! empty( $_POST ) || ! empty( $_FILES ) || self::unread_body() ) {
					return 'body:login';
				}
				if ( ! self::plain_query( false, true ) ) {
					return 'query:login';
				}
				$extra = array_diff( $keys, self::login_keys() );
				return $extra ? 'login-field:' . reset( $extra ) : '';
			case 'admin':
				$pagenow = self::pagenow();
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- cut to [a-z0-9_-] and compared with the listed screens
				$page    = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', is_string( $_GET['page'] ?? '' ) ? (string) ( $_GET['page'] ?? '' ) : '?' ) );
				return self::screen_allowed( (string) $pagenow, $page, $keys ) ? '' : 'admin:' . $pagenow . ( '' !== $page ? '?page=' . $page : '' );
			case 'xmlrpc':
				return 'xmlrpc';
		}
		$home = function_exists( 'home_url' ) ? (string) parse_url( home_url( '/' ), PHP_URL_PATH ) : '/';
		return self::front_allowed( $path, $keys, '' !== $home ? $home : '/' ) ? '' : 'front:' . $path;
	}

	/** The REST route a request names (?rest_route= or the address after /wp-json/); null when it is written oddly. */
	private static function rest_route( $path ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- checked for an odd path, then compared with the listed routes
		$route = isset( $_GET['rest_route'] ) ? ( is_string( $_GET['rest_route'] ) ? $_GET['rest_route'] : '//' ) : '';
		if ( '' !== $route ) {
			return self::odd_path( $route ) ? null : $route;
		}
		$prefix = trim( function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json', '/' ) . '/';
		foreach ( array( self::home_path() . $prefix, self::home_path() . 'index.php/' . $prefix ) as $start ) {   // from the start of the address only
			if ( 0 === strpos( $path . '/', $start ) ) {
				$route = '/' . ltrim( (string) substr( $path, strlen( $start ) ), '/' );
			}
		}
		return $route;
	}

	/* ================================================================== the early gate (before any plugin loads) */

	/**
	 * THE GATE, BEFORE ANY PLUGIN LOADS. Called by the must-use file (wp-content/mu-plugins/0-zdz-privacy-view-gate.php,
	 * shipped in this theme's mu-plugin/ folder and installed by an administrator from Zorderz -> Privacy View) when a
	 * sign-in cookie on the request names an account in Privacy View -- before any regular plugin's file has run, so
	 * before a page cache's own request handling, a sign-in plugin, and every plugins_loaded and setup_theme handler.
	 *
	 * Three looks, then:
	 *   1. here, before the plugins. WordPress cannot yet say who is signed in (its sign-in check is loaded after the
	 *      plugins), so the request is judged on the cookie's word and held to the account's lists as they stand
	 *      before any app is there to ask (before_apps()). wp-login.php and the sign-in routes and pages are let by,
	 *      because a browser can carry the account's cookie after its session ended and must be able to sign in.
	 *   2. plugins_loaded, first of all (early_confirm()). WordPress says whose cookie it really is, and a request that
	 *      really is the account's is judged in full: refused there, before any plugin's plugins_loaded or setup_theme
	 *      handler.
	 *   3. the gate proper (gate(), after_setup_theme), as on a site without the file, where the apps themselves answer.
	 * Cookies the account does not need are taken out here, before anything reads them.
	 */
	public static function early_gate( $login ) {
		if ( self::$early_done ) {
			return;
		}
		self::$early_done  = true;
		self::$early_login = (string) $login;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- $_REQUEST rebuilt from the request's own fields, exactly as WordPress does after plugins_loaded
		$_REQUEST = array_merge( (array) $_GET, (array) $_POST );   // what WordPress makes of it later (wp_magic_quotes); never the cookies PHP may have put in it
		self::keep_cookies();
		$why = self::early_verdict();
		if ( '' !== $why ) {
			self::refuse_early( $why );
			return;
		}
		if ( function_exists( 'add_action' ) ) {
			add_action( 'plugins_loaded', array( __CLASS__, 'early_confirm' ), PHP_INT_MIN );
		}
	}

	/** The verdict before WordPress can say who is signed in ('' = let by for now). */
	public static function early_verdict() {
		self::$early = true;
		try {
			$kind = self::request_kind();
			if ( 'login' === $kind || ( 'rest' === $kind && in_array( '/' . trim( (string) self::rest_route( self::raw_path() ), '/' ), self::signin_rest(), true ) )
				|| ( 'front' === $kind && self::signin_page() ) ) {
				// signing in (or out): judged at plugins_loaded, once WordPress says whose cookie it really is
				$h = self::trigger_header();
				return '' === $h ? ( self::overridden() ? 'override' : '' ) : 'header:' . $h;
			}
			return self::verdict();
		} finally {
			self::$early = false;
		}
	}

	/** GET or HEAD of the app's own /login/ or /register/ page, with only its listed keys, its address as PHP read it and no body. */
	private static function signin_page() {
		$path = self::raw_path();
		$home = self::home_path();
		$page = false;
		foreach ( self::SIGNIN_PAGES as $slug ) {
			if ( $path === $home . $slug || $path === $home . $slug . '/' ) {
				$page = true;
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only whether a body or a file came at all
		return $page && in_array( self::method(), array( 'GET', 'HEAD' ), true ) && empty( $_POST ) && empty( $_FILES )
			&& ! array_diff( self::query_keys(), self::SIGNIN_PAGE_KEYS ) && self::plain_query( false, true ) && ! self::unread_body();
	}

	/**
	 * Who WordPress will say is signed in, asked WITHOUT settling it. Calling wp_get_current_user() here would fix the
	 * user before the theme's own determine_current_user filters exist (Demo Mode switches a device's user there), so
	 * the sign-in cookie is checked the way WordPress's own two filters check it: the wp-admin cookie, then the
	 * signed-in cookie. Either of them naming the account counts.
	 */
	private static function cookie_user() {
		if ( ! function_exists( 'wp_validate_auth_cookie' ) ) {
			return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;   // (WordPress's sign-in functions are always there at plugins_loaded)
		}
		$uid = (int) wp_validate_auth_cookie( '', '' );
		if ( ! $uid || ! self::is_on( $uid ) ) {
			$other = (int) wp_validate_auth_cookie( '', 'logged_in' );
			$uid   = $other ? $other : $uid;
		}
		return $uid;
	}

	/** plugins_loaded, first of all: now WordPress can say who it is. The account's request in full. */
	public static function early_confirm() {
		$uid = self::cookie_user();
		if ( $uid <= 0 || ! self::is_on( $uid ) ) {
			return;   // the cookie named the account, but it signs nobody (or somebody else) in: not the account's
		}
		self::keep_cookies();
		// An app loads its classes and adds its filters later on this same hook, so "is that app ready?" and "what did
		// the apps declare?" cannot be asked of them here: before_apps(). The request is still held to its action,
		// fields, method and address, and to whether a direct-message partner was chosen.
		self::$defer_ready = true;
		try {
			$why = self::verdict();
		} finally {
			self::$defer_ready = false;
		}
		if ( '' !== $why ) {
			self::refuse_early( $why );
		}
	}

	/** Tests replace the ending (a refusal ends the request; a test cannot). */
	public static $halt = null;

	/**
	 * A refusal before the theme has loaded: the same 403, code, sentence and one word as the gate's, written plainly
	 * (wp_die, wp_send_json and translations lean on parts of WordPress that are not there yet), never cached.
	 */
	private static function refuse_early( $why ) {
		error_log( 'Zorderz Privacy View: refused (before plugins) ' . self::plain_line( $why ) );
		self::$early = true;
		$kind        = self::request_kind();
		self::$early = false;
		$json        = 'ajax' === $kind || 'rest' === $kind;
		$body        = $json
			? (string) json_encode( array( 'success' => false, 'data' => array( 'code' => 'zdz_privacy_view', 'message' => 'Not available in Privacy View.', 'why' => self::why_word( $why ) ) ) )
			: '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Privacy View</title></head><body><p>This is not available in Privacy View (customer details are hidden and nothing can be changed from this account).</p></body></html>';
		if ( null !== self::$halt ) {
			call_user_func( self::$halt, $why, $json, $body );
			return;
		}
		if ( ! headers_sent() ) {
			http_response_code( 403 );
			header( 'Content-Type: ' . ( $json ? 'application/json' : 'text/html' ) . '; charset=utf-8' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'X-Robots-Tag: noindex' );
			header( 'X-Zorderz-Privacy-Gate: before-plugins' );   // tells this refusal from the gate proper's
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed text and one word from a fixed list (why_word()): JSON-encoded or constant HTML, nothing the request wrote
		echo $body;
		exit;
	}

	public static function gate() {
		if ( ! self::is_on() ) {
			return;
		}
		self::start();
		self::keep_cookies();   // (again: the early gate took them first, where its file is installed)
		$why = self::verdict();
		if ( '' === $why ) {
			return;
		}
		error_log( 'Zorderz Privacy View: refused ' . self::plain_line( $why ) );
		$kind = self::request_kind();
		if ( 'ajax' === $kind || 'rest' === $kind ) {
			wp_send_json_error( array( 'code' => 'zdz_privacy_view', 'message' => __( 'Not available in Privacy View.', 'zorderz' ), 'why' => self::why_word( $why ) ), 403 );
		}
		wp_die( esc_html__( 'This is not available in Privacy View (customer details are hidden and nothing can be changed from this account).', 'zorderz' ), esc_html__( 'Privacy View', 'zorderz' ), array( 'response' => 403 ) );
	}

	public static function refuse( $unused = null ) {
		if ( ! self::is_on() ) {
			return $unused;
		}
		wp_die( esc_html__( 'Privacy View is read-only: nothing can be saved or sent from this account.', 'zorderz' ), esc_html__( 'Privacy View', 'zorderz' ), array( 'response' => 403 ) );
	}

	/** The site's own path ("/" unless WordPress lives in a folder), with a slash at each end. */
	private static function home_path() {
		$home = function_exists( 'home_url' ) ? (string) parse_url( home_url( '/' ), PHP_URL_PATH ) : '/';
		return '/' . ltrim( trim( $home, '/' ) . '/', '/' );
	}

	/**
	 * A REST call, from the request alone: only where WordPress itself routes one. The address BEGINS with the site's
	 * /wp-json/ (or /index.php/wp-json/), or it is the home address with ?rest_route=. "/wp-json/" somewhere in another
	 * address, or ?rest_route= on another script, is not one.
	 */
	private static function is_rest_request() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( 'index.php' !== self::pagenow() ) {
			return false;
		}
		$path = self::raw_path();
		$home = self::home_path();
		if ( isset( $_GET['rest_route'] ) ) {
			return $path === $home || $path === $home . 'index.php';
		}
		$prefix = trim( function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json', '/' ) . '/';
		return 0 === strpos( $path . '/', $home . $prefix ) || 0 === strpos( $path . '/', $home . 'index.php/' . $prefix );
	}

	/** Second net for wp-admin (the gate has already decided). */
	public static function guard_admin() {
		if ( ! self::is_on() ) {
			return;
		}
		if ( '' !== self::verdict() ) {
			self::gate();
		}
	}

	public static function guard_rest( $result, $server = null, $request = null ) {
		if ( ! self::is_on() || ! is_object( $request ) || ! method_exists( $request, 'get_method' ) ) {
			return $result;
		}
		self::start();   // signed in later than the gate (it saw no user): the output filter starts here
		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( self::rest_allowed( $route, $request->get_method() ) ) {
			return $result;
		}
		return new WP_Error( 'zdz_privacy_view', __( 'Not available in Privacy View.', 'zorderz' ), array( 'status' => 403 ) );
	}

	/** Heartbeat carries autosaves and post locks: the account sends none. */
	public static function heartbeat( $response, $data = array() ) {
		if ( self::is_on() ) {
			return array();
		}
		return $response;
	}

	/** The WordPress toolbar names the account and links to screens it may not open. */
	public static function admin_bar( $show ) {
		return self::is_on() ? false : $show;
	}

	/* ================================================================== the flag */

	public static function profile_field( $user ) {
		if ( ! current_user_can( 'edit_users' ) || ! is_object( $user ) || (int) $user->ID === (int) get_current_user_id() ) {
			return;
		}
		$on = self::is_on( (int) $user->ID );
		echo '<h2>' . esc_html__( 'Privacy View', 'zorderz' ) . '</h2><table class="form-table"><tr><th>' . esc_html__( 'Privacy View', 'zorderz' ) . '</th><td><label><input type="checkbox" name="zdz_privacy_view" value="1"' . ( $on ? ' checked' : '' ) . '> ';
		echo esc_html__( 'This account sees pseudonyms instead of customer and team details, and cannot change anything.', 'zorderz' );
		echo '</label><p class="description">' . esc_html__( 'Only an administrator can change this, and never on their own account.', 'zorderz' ) . '</p></td></tr></table>';
		wp_nonce_field( 'zdz_privacy_view_' . (int) $user->ID, 'zdz_privacy_view_nonce' );
	}

	public static function profile_save( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! current_user_can( 'edit_users' ) || $user_id === (int) get_current_user_id() || self::is_on() ) {
			return;
		}
		$nonce = isset( $_POST['zdz_privacy_view_nonce'] ) ? (string) wp_unslash( $_POST['zdz_privacy_view_nonce'] ) : '';   // phpcs:ignore
		if ( ! wp_verify_nonce( $nonce, 'zdz_privacy_view_' . $user_id ) ) {
			return;
		}
		if ( ! empty( $_POST['zdz_privacy_view'] ) ) {   // phpcs:ignore
			update_user_meta( $user_id, self::META, '1' );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	/* ================================================================== pseudonyms */

	/** The check character for 4 characters of ABC (weights 1-5, sum mod 37; value 36 is "_"). */
	public static function check_char( $body ) {
		$body = strtoupper( (string) $body );
		if ( 4 !== strlen( $body ) ) {
			return null;
		}
		$sum = 0;
		for ( $i = 0; $i < 4; $i++ ) {
			$v = strpos( self::ABC, $body[ $i ] );
			if ( false === $v ) {
				return null;
			}
			$sum += ( $i + 1 ) * $v;
		}
		$c = ( ( 37 - ( $sum % 37 ) ) % 37 ) * 15 % 37;
		return 36 === $c ? '_' : self::ABC[ $c ];
	}

	public static function well_formed( $code ) {
		$code = strtoupper( (string) $code );
		return 1 === preg_match( '/^[0-9A-Z]{4}[0-9A-Z_]$/', $code ) && self::check_char( substr( $code, 0, 4 ) ) === $code[4];
	}

	private static function key( $purpose ) {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) : 'zdz-no-salts';
		if ( self::labels() ) {
			return hash( 'sha256', 'zdz-privacy-view-v1|' . $purpose . '|' . $salt, true );   // the labelled, lasting codes
		}
		// mixed with this reply's tag, so the same customer has another code in the next reply
		return hash( 'sha256', 'zdz-privacy-view-v2|' . $purpose . '|' . self::view() . '|' . $salt, true );
	}

	/** Consonants only: a tag never spells a word or a name. */
	const VIEW_ABC = 'BCDFGHJKLMNPQRSTVWXZ';

	/**
	 * This reply's tag. Every reply the account gets has its own random tag, and every pseudonym in it is "Tag·CODE".
	 * Within one reply a customer keeps one code (a list still reads); in the next reply, or the same chat opened again,
	 * everything has new codes, so none of them becomes an identifier over time, and no label says what kind of thing
	 * was hidden. The tag is not stored: the administrator's look-up reads it from the pseudonym and works the codes out
	 * again with the site's key, which the account never has.
	 */
	public static function view() {
		if ( null === self::$view ) {
			$t = '';
			for ( $i = 0; $i < 4; $i++ ) {
				$t .= self::VIEW_ABC[ random_int( 0, strlen( self::VIEW_ABC ) - 1 ) ];
			}
			self::$view = $t[0] . strtolower( substr( $t, 1 ) );
		}
		return self::$view;
	}

	/** Start a new reply's codes (each request is a reply; tests and the look-up call this). */
	public static function new_view( $tag = null ) {
		self::$view = null !== $tag && preg_match( '/^[' . self::VIEW_ABC . '][' . strtolower( self::VIEW_ABC ) . ']{3}$/', (string) $tag ) ? (string) $tag : null;
		self::$memo = array();
	}

	/** Labelled, lasting pseudonyms ("Cust·…"), only where a filter asks for them. Off by default. */
	private static function labels() {
		if ( null === self::$labels ) {
			self::$labels = function_exists( 'apply_filters' ) && true === apply_filters( 'zdz_privacy_view_labels', false );
		}
		return self::$labels;
	}

	/** What goes before the code: this reply's tag (or, in the labelled mode, what it is). */
	private static function tag( $label ) {
		return ( self::labels() ? $label : self::view() ) . self::MID;
	}

	public static function code( $purpose, $value ) {
		$mk = $purpose . "\0" . $value . "\0" . ( self::labels() ? '' : self::view() );
		if ( isset( self::$memo[ $mk ] ) ) {
			return self::$memo[ $mk ];
		}
		$raw  = hash_hmac( 'sha256', (string) $value, self::key( $purpose ), true );
		$body = '';
		for ( $i = 0; $i < 4; $i++ ) {
			$body .= self::ABC[ ord( $raw[ $i ] ) % 36 ];
		}
		return self::$memo[ $mk ] = $body . self::check_char( $body );
	}

	/** A name's normal form: the coder's, so a pseudonym and the coder's index agree. */
	public static function norm( $s ) {
		if ( class_exists( 'ZDZ_Private' ) && method_exists( 'ZDZ_Private', 'norm' ) ) {
			return (string) ZDZ_Private::norm( (string) $s );
		}
		$s = strtolower( function_exists( 'remove_accents' ) ? remove_accents( (string) $s ) : (string) $s );
		$s = (string) preg_replace( '/[^a-z0-9 ]+/', ' ', $s );
		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	/** The coder's common words (never taken for a name on their own: its list and the business's plain words), or none. */
	private static function common_words() {
		if ( class_exists( 'ZDZ_Private' ) && method_exists( 'ZDZ_Private', 'common_words' ) ) {
			return array_map( 'strval', array_keys( (array) ZDZ_Private::common_words() ) );
		}
		return defined( 'ZDZ_Private::COMMON' ) ? (array) constant( 'ZDZ_Private::COMMON' ) : array();
	}

	public static function customer( $name ) {
		return self::tag( 'Cust' ) . self::code( 'customer', self::norm( (string) $name ) );
	}

	public static function staff( $user_id ) {
		return self::tag( 'Staff' ) . self::code( 'staff', 'u' . (int) $user_id );
	}

	public static function doc( $kind, $number ) {
		$kind = 'estimate' === $kind ? 'estimate' : 'invoice';
		return self::tag( 'estimate' === $kind ? 'Est' : 'Inv' ) . self::code( $kind, ltrim( trim( (string) $number ), '#0' ) );
	}

	public static function email( $e ) {
		return self::tag( 'email' ) . self::code( 'email', strtolower( trim( (string) $e ) ) );
	}

	public static function phone( $p ) {
		return self::tag( 'phone' ) . self::code( 'phone', substr( preg_replace( '/\D+/', '', (string) $p ), -10 ) );
	}

	public static function street( $s ) {
		return self::tag( 'street' ) . self::code( 'street', self::norm( (string) $s ) );
	}

	/** A sealed detail (opened from an ⟦e⟧ code) as its pseudonym, by what it looks like. */
	public static function detail( $plain ) {
		$plain = trim( (string) $plain );
		if ( false !== strpos( $plain, '@' ) ) {
			return self::email( $plain );
		}
		if ( strlen( preg_replace( '/\D+/', '', $plain ) ) >= 7 && preg_match( '/^[\d\s().+\-]+$/', $plain ) ) {
			return self::phone( $plain );
		}
		if ( preg_match( '/^\d/', $plain ) ) {
			return self::street( $plain );
		}
		return self::customer( $plain );
	}

	/* ================================================================== the passes */

	private static function coder() {
		if ( null === self::$coder ) {
			if ( ! class_exists( 'ZDZ_Private' ) || ! method_exists( 'ZDZ_Private', 'for' ) ) {
				throw new RuntimeException( 'coder missing' );   // refused: nothing is sent unmasked
			}
			self::$coder = ZDZ_Private::for( 'privacy-view' );
			if ( ! self::$coder ) {
				throw new RuntimeException( 'coder missing' );
			}
		}
		return self::$coder;
	}

	/** The coder's places written as phrases ("two words or more"), or none. */
	private static function coder_places() {
		if ( class_exists( 'ZDZ_Private' ) && method_exists( 'ZDZ_Private', 'places' ) ) {
			return (array) ZDZ_Private::places();
		}
		return defined( 'ZDZ_Private::PLACES' ) ? (array) constant( 'ZDZ_Private::PLACES' ) : array();
	}

	/**
	 * Team members: 'full' => [name => user id] (display names and first+last, longest first: used everywhere) and
	 * 'words' => [word => user id] (a first or last name on its own, 3+ letters, not a plain word: prose only),
	 * 'initials' => [XY => user id] (the salesperson code: prose only; see staff_prose() and initials_prose()),
	 * 'logins' => [login => user id], 'display' => [one-word display name, lower case => user id] (name fields only).
	 */
	public static function staff_names() {
		if ( null !== self::$staff ) {
			return self::$staff;
		}
		$full   = array();
		$words  = array();
		$ini    = array();
		$logins = array();
		$display = array();
		if ( function_exists( 'get_users' ) ) {
			foreach ( (array) get_users( array( 'fields' => array( 'ID', 'display_name', 'user_login' ), 'number' => 300 ) ) as $u ) {
				$id = (int) ( is_object( $u ) ? $u->ID : 0 );
				if ( $id <= 0 || self::is_on( $id ) ) {
					continue;   // a Privacy View account is not a team member to hide
				}
				$lg = strtolower( trim( (string) ( $u->user_login ?? '' ) ) );
				if ( mb_strlen( $lg ) >= 2 ) {
					$logins[ $lg ] = $id;
				}
				$dn = strtolower( trim( (string) ( $u->display_name ?? '' ) ) );
				if ( mb_strlen( $dn ) >= 2 && false === strpos( $dn, ' ' ) ) {
					$display[ $dn ] = $id;   // a one-word display name ("admin") in a field that holds a person's name is that person
				}
				$first = function_exists( 'get_user_meta' ) ? trim( (string) get_user_meta( $id, 'first_name', true ) ) : '';
				$last  = function_exists( 'get_user_meta' ) ? trim( (string) get_user_meta( $id, 'last_name', true ) ) : '';
				$i     = function_exists( 'get_user_meta' ) ? strtoupper( trim( (string) get_user_meta( $id, 'zdz_user_initials', true ) ) ) : '';
				foreach ( array( (string) $u->display_name, trim( $first . ' ' . $last ) ) as $n ) {
					$n = trim( preg_replace( '/\s+/', ' ', $n ) );
					if ( false !== strpos( $n, ' ' ) && mb_strlen( $n ) >= 5 ) {
						$full[ $n ] = $id;
					}
				}
				foreach ( array_merge( array( $first, $last ), preg_split( '/\s+/', (string) $u->display_name ) ) as $w ) {
					$w = trim( (string) $w, " .,'" );
					if ( mb_strlen( $w ) >= 3 && preg_match( '/^\p{Lu}/u', $w ) && ! in_array( strtolower( $w ), self::common_words(), true )
						&& ! in_array( strtolower( $w ), self::SAFE_WORDS, true ) ) {
						$words[ $w ] = $id;
					}
				}
				if ( preg_match( '/^[A-Z]{2,4}$/', $i ) ) {
					$ini[ $i ] = $id;
				}
			}
		}
		$len = function ( $a, $b ) { return mb_strlen( $b ) - mb_strlen( $a ); };
		uksort( $full, $len );
		uksort( $words, $len );
		return self::$staff = array( 'full' => $full, 'words' => $words, 'initials' => $ini, 'logins' => $logins, 'display' => $display );
	}

	/**
	 * The business's own vocabulary: what its Identity Pack wrote (knowledge.yml `privacy_view`) and what a plugin adds
	 * on zdz_privacy_view_words. array( 'words' => [lower case word => true], 'phrases' => [lower case phrase => true] ).
	 */
	public static function vocabulary() {
		if ( null !== self::$vocab ) {
			return self::$vocab;
		}
		$opt = function_exists( 'get_option' ) ? get_option( self::VOCAB_OPT, array() ) : array();
		$in  = array(
			'words'   => is_array( $opt ) ? (array) ( $opt['words'] ?? array() ) : array(),
			'phrases' => is_array( $opt ) ? (array) ( $opt['phrases'] ?? array() ) : array(),
		);
		$f = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_privacy_view_words', $in ) : $in;
		if ( ! is_array( $f ) ) {
			$f = $in;
		}
		$out = array( 'words' => array(), 'phrases' => array() );
		foreach ( (array) ( $f['words'] ?? array() ) as $w ) {
			$w = mb_strtolower( trim( (string) $w ) );
			if ( preg_match( '/^[\p{L}][\p{L}\'’\-]{0,39}$/u', $w ) ) {
				$out['words'][ $w ] = true;
			}
		}
		foreach ( (array) ( $f['phrases'] ?? array() ) as $p ) {
			$p = trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( (string) $p ) ) );
			if ( '' !== $p && mb_strlen( $p ) <= 80 ) {
				$out['phrases'][ $p ] = true;
			}
		}
		return self::$vocab = $out;
	}

	/** The words of the business's own names (Business Profile): never a customer's name. Lower case. */
	private static function business_words() {
		$names = array();
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			foreach ( array( 'identity.legal_name', 'identity.trading_name', 'identity.short_name', 'brand.pwa.name', 'brand.pwa.short_name' ) as $k ) {
				$names[] = (string) ZDZ_Business_Profile::get( $k, '' );
			}
			foreach ( (array) ZDZ_Business_Profile::get( 'identity.former_names', array() ) as $f ) {
				$names[] = is_scalar( $f ) ? (string) $f : '';
			}
		}
		$out = array();
		foreach ( $names as $n ) {
			$parts = preg_split( '/[^\p{L}]+/u', mb_strtolower( $n ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $parts as $w ) {
				if ( mb_strlen( $w ) >= 2 ) {
					$out[ $w ] = true;
				}
			}
			if ( count( $parts ) > 1 ) {
				$out[ implode( '', $parts ) ] = true;   // the name written as one word
			}
		}
		return $out;
	}

	/** The places, as phrases (lower case, single spaces). */
	private static function places() {
		if ( null === self::$places ) {
			$p = array();
			foreach ( array_merge( self::coder_places(), self::SAFE_PHRASES ) as $x ) {
				$p[ strtolower( trim( preg_replace( '/\s+/', ' ', (string) $x ) ) ) ] = true;
			}
			$p += self::vocabulary()['phrases'];
			$p += self::product_terms()['phrases'];
			self::$places = $p;
		}
		return self::$places;
	}

	/**
	 * The catalog's product words as safe vocabulary: the price book's families (label, brand) and items (brand, model),
	 * and the Item Engine's item names, aliases and brands. A word on its own when it is not also a census first or
	 * last name, otherwise only as the whole phrase (a brand that is also a surname is safe written whole, while the
	 * surname alone stays a name). Numbers, sizes and one- or two-letter pieces are left out.
	 * Returns array( 'words' => array( word => true ), 'phrases' => array( 'lower case phrase' => true ) ).
	 */
	public static function product_terms() {
		if ( null !== self::$products ) {
			return self::$products;
		}
		$out   = array( 'words' => array(), 'phrases' => array() );
		$names = array();
		$book  = class_exists( 'ZDZ_Price_Book' ) && method_exists( 'ZDZ_Price_Book', 'published' ) ? ZDZ_Price_Book::published() : null;
		if ( is_array( $book ) ) {
			foreach ( (array) ( $book['families'] ?? array() ) as $f ) {
				$names[] = (string) ( $f['brand'] ?? '' );
				$names[] = (string) ( $f['label'] ?? '' );
			}
			foreach ( (array) ( $book['items'] ?? array() ) as $it ) {
				$b = (string) ( $it['brand'] ?? '' );
				$m = (string) ( $it['model'] ?? '' );
				$names[] = $b;
				$names[] = $m;
				$names[] = trim( $b . ' ' . $m );
			}
		}
		if ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'all' ) ) {
			try {
				foreach ( (array) ZDZ_Item_Engine::all() as $it ) {
					$names[] = (string) ( $it['display_name'] ?? '' );
					foreach ( (array) ( $it['aliases'] ?? array() ) as $a ) {
						$names[] = is_array( $a ) ? (string) ( $a['value'] ?? '' ) : (string) $a;
					}
					$names[] = is_scalar( $it['attributes']['brand'] ?? null ) ? (string) $it['attributes']['brand'] : '';
				}
			} catch ( \Throwable $e ) {
				unset( $e );   // no catalog: no product words, so more is masked, never less
			}
		}
		if ( ! array_filter( $names ) ) {
			return self::$products = $out;
		}
		$W = self::words();
		foreach ( array_unique( $names ) as $n ) {
			$parts = preg_split( '/[^\p{L}]+/u', mb_strtolower( $n ), -1, PREG_SPLIT_NO_EMPTY );
			$named = false;
			$keep  = array();
			foreach ( $parts as $w ) {
				if ( mb_strlen( $w ) < 3 ) {
					continue;
				}
				$h = self::name_hash( $w );
				if ( isset( $W['cfirst'][ $h ] ) || isset( $W['clast'][ $h ] ) ) {
					$named = true;   // also a person's name: only as part of the whole phrase
					continue;
				}
				$keep[ $w ] = true;
			}
			$out['words'] += $keep;
			if ( $named && count( $parts ) >= 2 ) {
				$out['phrases'][ implode( ' ', $parts ) ] = true;
			}
		}
		return self::$products = $out;
	}

	/** Safe words (single words). */
	private static function safe_words() {
		if ( null === self::$safe ) {
			$s = array_flip( self::SAFE_WORDS );
			// a place of two or three words is safe as a PHRASE (names_over), never word by word: many of their words
			// are first names too.
			$s += self::business_words();
			$s += self::vocabulary()['words'];
			$s += self::product_terms()['words'];
			self::$safe = $s;
		}
		return self::$safe;
	}

	/**
	 * The coder sometimes takes an ordinary word for a name (a customer record that holds "Apps" or "Tools"). After it
	 * has run, each code is lined up with the words it replaced (the text around the codes is unchanged); a code that
	 * replaced ONE ordinary English word that is not a census name is put back as that word. Anything that cannot be
	 * lined up stays coded.
	 */
	public static function unmask_ordinary( $orig, $enc ) {
		$orig = (string) $orig;
		$enc  = (string) $enc;
		if ( $orig === $enc || false === strpos( $enc, '⟦' ) ) {
			return $enc;
		}
		$parts = preg_split( '/(⟦[ce]:[A-Za-z0-9_\-]*⟧)/u', $enc, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $enc;
		}
		$W    = self::words();
		$safe = self::safe_words();
		$pos  = 0;
		$out  = '';
		$n    = count( $parts );
		for ( $i = 0; $i < $n; $i += 2 ) {
			$seg = $parts[ $i ];
			if ( '' !== $seg ) {
				if ( substr( $orig, $pos, strlen( $seg ) ) !== $seg ) {
					return $enc;   // not lined up (the coder changed something else): keep every code
				}
				$pos += strlen( $seg );
			}
			$out .= $seg;
			if ( $i + 1 >= $n ) {
				break;
			}
			$code = $parts[ $i + 1 ];
			$next = $parts[ $i + 2 ] ?? '';
			if ( '' === $next ) {
				if ( $i + 3 < $n ) {
					return $enc;   // two codes side by side: cannot tell where one ends
				}
				$span = substr( $orig, $pos );
			} else {
				$at = strpos( $orig, $next, $pos );
				if ( false === $at ) {
					return $enc;
				}
				$span = substr( $orig, $pos, $at - $pos );
			}
			$pos += strlen( $span );
			// a quote or apostrophe at either edge of what the coder took ("app'" in 'Reload the app') stays outside
			// the code: inside a script it closes a string
			preg_match( '/^([\'’"`]*)(.*?)([\'’"`]*)$/us', $span, $qm );
			$lead  = $qm[1] ?? '';
			$trail = $qm[3] ?? '';
			$core  = $qm[2] ?? $span;
			$k     = mb_strtolower( trim( $core ) );
			$flat  = trim( $core ) === $k || trim( $core ) === mb_strtoupper( trim( $core ) );   // "link", "POST": not written as a name
			// each part ("arrow-right": arrow, right) must be: the business's or platform's own word; or an ordinary word
			// that is either written in lower case / capitals ("link", "POST") or not also a census name ("Apps")
			$whole = trim( $orig ) === trim( $span ) || trim( $orig ) === trim( $core );   // the whole value is this word: a label
			if ( preg_match( '/^[\p{L}\s\\\\*+?]+$/u', $k ) && preg_match_all( '/\p{L}{2,}/u', $k, $ww ) && count( $ww[0] ) > 1
				&& ! array_diff( $ww[0], array_keys( array_intersect_key( $safe, array_flip( $ww[0] ) ) ) ) ) {
				$out .= $lead . $core . $trail;   // every word one of the safe vocabulary (the business's name, even in a pattern)
				continue;
			}
			$ordinary = 1 === preg_match( '/^\p{L}{2,}(?:-\p{L}{2,})*$/u', $k );
			foreach ( $ordinary ? explode( '-', $k ) : array() as $w ) {
				$plain = isset( $W['ord'][ $w ] ) || null !== self::stem_known( $w );
				$named = isset( $W['cfirst'][ self::name_hash( $w ) ] ) || isset( $W['clast'][ self::name_hash( $w ) ] );
				// "you", "the": never a name; a label that is one ordinary word ("Field"): put back
				if ( ! ( isset( $safe[ $w ] ) || isset( $W['stop'][ $w ] ) || ( $plain && ( $flat || $whole || ! $named ) ) ) ) {
					$ordinary = false;
					break;
				}
			}
			$out .= $lead . ( $ordinary ? $core : $code ) . $trail;
		}
		return $out;
	}

	/** ⟦c⟧ and ⟦e⟧ codes as pseudonyms; an ⟦e⟧ that will not open is "[private]". */
	public static function from_codes( $t ) {
		$coder = self::coder();
		return (string) preg_replace_callback( '/⟦([ce]):([A-Za-z0-9_\-]*)⟧/u', function ( $m ) use ( $coder ) {
			if ( 'c' === $m[1] ) {
				$n = method_exists( $coder, 'ledger_name' ) ? $coder->ledger_name( (int) $m[2] ) : null;
				return null === $n ? self::tag( 'Cust' ) . self::code( 'customer-id', $m[2] ) : self::customer( $n );
			}
			$o = $coder->open( $m[2] );
			if ( null === $o ) {
				return '[private]';
			}
			$ini = self::staff_names()['initials'];
			if ( isset( $ini[ strtoupper( trim( $o ) ) ] ) && preg_match( '/^[A-Za-z]{2,4}$/', trim( $o ) ) ) {
				return self::staff( $ini[ strtoupper( trim( $o ) ) ] );   // a salesperson code the coder sealed ("Paid · AB")
			}
			return self::detail( $o );
		}, (string) $t );
	}

	private static function staff_full( $t ) {
		foreach ( self::staff_names()['full'] as $name => $id ) {
			if ( false !== stripos( $t, $name ) ) {
				$t = (string) preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $name, '/' ) . '(?![\p{L}\p{N}])/iu', self::staff( $id ), $t );
			}
		}
		return $t;
	}

	private static function staff_prose( $t ) {
		$s = self::staff_names();
		foreach ( $s['words'] as $w => $id ) {
			if ( false !== strpos( $t, $w ) ) {   // case-sensitive: a name is written with its capital
				$t = (string) preg_replace( '/(?<![\p{L}\p{N}·])' . preg_quote( $w, '/' ) . '(?![\p{L}\p{N}])/u', self::staff( $id ), $t );
			}
		}
		foreach ( $s['initials'] as $i => $id ) {
			$t = (string) preg_replace( '/\(' . $i . '\)|(?<=\bby\s)' . $i . '\b|(?<=\bBy\s)' . $i . '\b|(?<=Submitted by:\s)' . $i . '\b/u', '(' . self::staff( $id ) . ')', $t );
		}
		return self::initials_prose( $t, $s['initials'] );
	}

	/**
	 * Initials that are also English words or common abbreviations: coded only in a list of codes or after a role word,
	 * never standing on their own in a sentence. The business's own short words (a region code, a product code) join
	 * them from its vocabulary (initials_words()).
	 */
	const INITIALS_WORDS = array( 'AD', 'AH', 'AM', 'AN', 'AS', 'AT', 'AX', 'BE', 'BY', 'DO', 'GO', 'HA', 'HE', 'HI', 'ID', 'IF',
		'IN', 'IS', 'IT', 'LA', 'ME', 'MY', 'NO', 'OF', 'OH', 'OK', 'ON', 'OR', 'OX', 'PM', 'RE', 'SO', 'TO', 'UP', 'US', 'WE', 'YE',
		'AC', 'DC', 'EV', 'HQ', 'HR', 'PC', 'PO', 'PS', 'QA', 'TV', 'UV', 'UK', 'EU', 'FYI', 'ASAP', 'AKA', 'ETA', 'TBD', 'USA' );
	/** Words that say the initials after them are a person's ("crew: JB", "assigned to MC"). Lower case; a plural "s" is
	 *  read too. The business's own role labels and the zdz_privacy_view_role_words filter add to them (role_words()). */
	const ROLE_WORDS = array( 'crew', 'tech', 'technician', 'installer', 'salesperson', 'sales rep', 'rep', 'seller', 'assigned', 'assigned to', 'helper' );

	/** INITIALS_WORDS and the business's own words of two to four letters (its Identity Pack's safe words, the
	 *  zdz_privacy_view_words filter), in capitals: a region code that is also a team member's initials stays a word. */
	private static function initials_words() {
		$out = array_flip( self::INITIALS_WORDS );
		foreach ( array_keys( self::vocabulary()['words'] ) as $w ) {
			$w = (string) $w;
			if ( preg_match( '/\A[a-z]{2,4}\z/', $w ) ) {
				$out[ strtoupper( $w ) ] = true;
			}
		}
		return $out;
	}

	/** ROLE_WORDS, the labels the business gives its roles, and what a plugin adds: lower-case words and phrases of letters. */
	public static function role_words() {
		$in = self::ROLE_WORDS;
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'get_role_labels' ) ) {
			$in = array_merge( $in, array_values( (array) ZDZ_User_Roles::get_role_labels() ) );
		}
		$f   = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_privacy_view_role_words', $in ) : $in;
		$out = array();
		foreach ( array_merge( self::ROLE_WORDS, is_array( $f ) ? $f : array() ) as $w ) {
			$w = is_string( $w ) ? trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( $w ) ) ) : '';
			if ( preg_match( '/\A\p{L}[\p{L} \-]{2,39}\z/u', $w ) && count( $out ) < 80 ) {
				$out[ $w ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * A team member's initials in a SENTENCE, beyond "(JB)" and "by JB" above: in a list of codes ("JB/MC", "JB, MC and
	 * IT"), after a role word ("crew: JB", "tech MC", "assigned to IT"), or on their own ("JB will call"). On their own
	 * only when the code is not also a word (initials_words(): "IT" stays a word there). Only in text with a space in
	 * it: a value that IS the code (an avatar's letters, a label) is left to its field's rule. Capitals only, whole
	 * tokens; never inside a code, a word or an address.
	 */
	private static function initials_prose( $t, $ini ) {
		$t = (string) $t;
		if ( ! $ini || ! preg_match( '/\s/u', trim( $t ) ) ) {
			return $t;
		}
		$keys = array_map( 'strval', array_keys( $ini ) );
		usort( $keys, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		$alt   = implode( '|', array_map( function ( $k ) { return preg_quote( (string) $k, '/' ); }, $keys ) );
		$edge  = '(?<![\p{L}\p{N}·@_\-\/.#=?&])';
		$end   = '(?![\p{L}\p{N}·@_\-(=]|\/\w|\.\w)';
		$wordy = self::initials_words();
		$one   = function ( $code ) use ( $ini ) {
			return isset( $ini[ $code ] ) ? self::staff( $ini[ $code ] ) : $code;
		};
		$codes = function ( $run ) use ( $alt, $one ) {
			return (string) preg_replace_callback( '/(?<![\p{L}\p{N}·])(' . $alt . ')(?![\p{L}\p{N}·])/u', function ( $m ) use ( $one ) {
				return $one( $m[1] );
			}, $run );
		};
		// a pattern that cannot run (the pattern engine's limits, on a very long run of codes) never empties the text:
		// every code in it is coded instead, word or not (more hidden, never less)
		$or_all = function ( $r ) use ( $t, $codes ) {
			return null === $r ? $codes( $t ) : (string) $r;
		};
		// a list of two or more codes (at most 40 in one run)
		$t = $or_all( preg_replace_callback( '/' . $edge . '(?:' . $alt . ')(?:\s*(?:\/|,|&|\+|\band\b|\bor\b)\s*(?:' . $alt . ')){1,40}' . $end . '/u', function ( $m ) use ( $codes ) {
			return $codes( $m[0] );
		}, $t ) );
		// after a role word ("crew: JB", "tech MC"); a code that is also a word only after a ":" or "-" ("crew IT help" stays)
		$roles = self::role_words();
		usort( $roles, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		$role = implode( '|', array_map( function ( $w ) { return str_replace( ' ', '\s+', preg_quote( $w, '/' ) ); }, $roles ) );
		$t    = $or_all( preg_replace_callback( '/(?<![\p{L}\p{N}])((?i:(?:' . $role . ')s?)(\s*[:\-]?\s*))(' . $alt . ')' . $end . '/u', function ( $m ) use ( $one, $wordy ) {
			if ( isset( $wordy[ $m[3] ] ) && ! preg_match( '/[:\-]/', $m[2] ) ) {
				return $m[0];
			}
			return $m[1] . $one( $m[3] );
		}, $t ) );
		// on its own, when the code is not also a word
		$own = array_values( array_filter( $keys, function ( $k ) use ( $wordy ) { return ! isset( $wordy[ $k ] ); } ) );
		if ( $own ) {
			$alt2 = implode( '|', array_map( function ( $k ) { return preg_quote( (string) $k, '/' ); }, $own ) );
			$t    = $or_all( preg_replace_callback( '/' . $edge . '(' . $alt2 . ')' . $end . '/u', function ( $m ) use ( $one ) {
				return $one( $m[1] );
			}, $t ) );
		}
		return $t;
	}

	private static function doc_pass( $t, $bare = true ) {
		$t = (string) preg_replace_callback( '/\b(Estimates?|Est\.?|EST|estimates?)(\s*(?:#|No\.?|no\.?|number|num\.?)?\s*[:\-]?\s*)#?(\d{3,8})\b/u', function ( $m ) {
			return $m[1] . str_replace( '#', '', $m[2] ) . self::doc( 'estimate', $m[3] );
		}, $t );
		$t = (string) preg_replace_callback( '/\b(Invoices?|Inv\.?|INV|invoices?|inv\.?)(\s*(?:#|No\.?|no\.?|number|num\.?)?\s*[:\-]?\s*)#?(\d{3,8})\b/u', function ( $m ) {
			return $m[1] . str_replace( '#', '', $m[2] ) . self::doc( 'invoice', $m[3] );
		}, $t );
		// "#1234" alone is a document number, but never a licence number ("License #1234", "Lic. #1234")
		$t = (string) preg_replace_callback( '/(?<![\w&\/·])(?<!License )(?<!License)(?<!Licence )(?<!Lic\. )(?<!Lic )#(\d{3,8})\b/u', function ( $m ) {
			return self::doc( self::known_docs()[ ltrim( $m[1], '0' ) ] ?? 'invoice', $m[1] );
		}, $t );
		// any number the ledger knows as an invoice or estimate number, written on its own ("| 1234 |"), in TEXT a
		// person reads (a sentence, a table: it has spaces); never in a value or an attribute (a cap of 1000, an SVG's
		// x, a font weight); not an amount ($, a decimal, a thousands comma), a percentage or a year
		$docs = $bare ? self::known_docs() : array();
		if ( ! $docs ) {
			return $t;
		}
		return (string) preg_replace_callback( '/(?<![\d$€£.,#\/\-:·\p{L}_])(\d{3,8})(?![\d%_\p{L}]|[.,]\d)/u', function ( $m ) use ( $docs ) {
			$n = ltrim( $m[1], '0' );
			if ( ! isset( $docs[ $n ] ) || ( 4 === strlen( $m[1] ) && (int) $m[1] >= 1990 && (int) $m[1] <= 2099 ) ) {
				return $m[0];
			}
			return self::doc( $docs[ $n ], $n );
		}, $t );
	}

	/** Where invoice and estimate numbers are kept: kind => array( table suffix, column ). The sales ledger by default;
	 *  an app that keeps its own adds it on zdz_privacy_view_doc_ledgers. Invoices last: a number that is both reads as
	 *  an invoice. */
	public static function doc_ledgers() {
		$l = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_privacy_view_doc_ledgers', array(
			'estimate' => array( 'zana_ledger_estimates', 'estimate_number' ),
			'invoice'  => array( 'zana_ledger_invoices', 'invoice_number' ),
		) ) : array();
		$out = array();
		foreach ( (array) $l as $kind => $tc ) {
			if ( in_array( $kind, array( 'estimate', 'invoice' ), true ) && is_array( $tc ) && isset( $tc[0], $tc[1] )
				&& preg_match( '/^[a-z0-9_]{1,64}$/', (string) $tc[0] ) && preg_match( '/^[a-z0-9_]{1,64}$/', (string) $tc[1] ) ) {
				$out[ $kind ] = array( (string) $tc[0], (string) $tc[1] );
			}
		}
		return $out;
	}

	/** Every invoice and estimate number the ledger holds: number => 'invoice'|'estimate'. Read once per request. */
	public static function known_docs() {
		if ( null !== self::$docs ) {
			return self::$docs;
		}
		$out = array();
		global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_col' ) ) {
			$quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			foreach ( self::doc_ledgers() as $kind => $tc ) {
				$t = $wpdb->prefix . $tc[0];
				foreach ( (array) $wpdb->get_col( "SELECT DISTINCT {$tc[1]} FROM {$t} WHERE {$tc[1]} <> '' LIMIT 200000" ) as $n ) {   // phpcs:ignore -- table and column checked above
					$n = ltrim( trim( (string) $n ), '#0' );
					if ( preg_match( '/^\d{3,8}$/', $n ) ) {
						$out[ $n ] = $kind;
					}
				}
			}
			if ( null !== $quiet ) {
				$wpdb->suppress_errors( $quiet );
			}
		}
		return self::$docs = (array) apply_filters( 'zdz_privacy_view_known_docs', $out );
	}

	/** A street address written out ("1450 Example St"): its pseudonym, whether or not the app knows it. */
	private static function street_pass( $t ) {
		$sfx = 'St|Street|Ave|Avenue|Rd|Road|Dr|Drive|Blvd|Boulevard|Ln|Lane|Ct|Court|Way|Pl|Place|Cir|Circle|Ter|Terrace|Trl|Trail|Pkwy|Parkway|Hwy|Highway|Row|Sq|Square|Glen|Loop|Pass|Path|Run|Vista|Point|Pt|Crest|Ridge|Heights|Hts|Cv|Cove';
		return (string) preg_replace_callback( '/(?<![\d\p{L}·])\d{1,6}(?:-?[A-Z])?\s+(?:[NSEW]\.?\s+)?(?:(?:Camino|Calle|Via|Avenida|Paseo)\s+(?:(?:de|del|la|las|los|el)\s+)*\p{Lu}[\p{L}\'’\-]*(?:\s+\p{Lu}[\p{L}\'’\-]*)?|(?:\p{Lu}[\p{L}\'’\-]*\.?\s+){1,4}(?:' . $sfx . ')\b\.?)/u', function ( $m ) {
			return self::street( $m[0] );
		}, $t );
	}

	/** The word lists (inc/data/privacy-view-words.php). Missing lists: an error, so the response is refused. */
	public static function words() {
		if ( null === self::$words ) {
			$f = __DIR__ . '/data/privacy-view-words.php';
			$w = is_readable( $f ) ? include $f : null;
			if ( ! is_array( $w ) || empty( $w['ord'] ) ) {
				throw new RuntimeException( 'Privacy View word lists missing' );
			}
			self::$words = array(
				'ord'    => array_flip( explode( ' ', (string) $w['ord'] ) ),
				'first'  => array_flip( explode( ' ', (string) ( $w['first'] ?? '' ) ) ),
				'last'   => array_flip( explode( ' ', (string) ( $w['last'] ?? '' ) ) ),
				'cfirst' => array_flip( explode( ' ', (string) ( $w['cfirst'] ?? '' ) ) ),
				'clast'  => array_flip( explode( ' ', (string) ( $w['clast'] ?? '' ) ) ),
				'stop'   => array_flip( explode( ' ', (string) ( $w['stop'] ?? '' ) ) ),
			);
		}
		return self::$words;
	}

	/**
	 * One capitalised word: [class, joins-as-first-name, joins-as-surname, month-name].
	 *   safe    a listed word (the platform's, the business's, a product, a place, a month, a label) or a short
	 *           acronym (YTD, HOA)
	 *   ord     an ordinary English word (the generated list)
	 *   name    anything else: nobody listed it as ordinary, so it is treated as a name
	 *   initial a single capital ("Q.")        hon  a courtesy title (Mr, Mrs, Dr ...)
	 */
	public static function word_class( $w ) {
		$plain = preg_replace( '/[\'’](?:s|t|re|ll|ve|d|m)$/iu', '', rtrim( (string) $w, '.' ) );
		$none  = array( 'cls' => '', 'first' => false, 'last' => false, 'month' => false, 'cf' => false, 'cl' => false, 'stop' => false, 'greet' => false );
		if ( 1 === mb_strlen( $plain ) ) {
			return array( 'cls' => 'initial' ) + $none;
		}
		$k = mb_strtolower( $plain );
		if ( in_array( $k, self::HONORIFICS, true ) ) {
			return array( 'cls' => 'hon' ) + $none;
		}
		$safe = self::safe_words();
		$W    = self::words();
		$r    = array( 'cls' => 'safe' ) + $none;
		$r['greet'] = in_array( $k, array( 'hi', 'hello', 'hey', 'dear', 'thanks', 'cheers', 'congrats', 'congratulations', 'welcome', 'bye' ), true );
		$r['stop']  = isset( $W['stop'][ $k ] );
		foreach ( preg_split( '/[\-\'’]+/u', $k, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			if ( mb_strlen( $part ) < 2 ) {
				continue;   // the O of O'Name, a d' ...
			}
			$h = self::name_hash( $part );   // the name lists are kept as hashes
			$r['cf'] = $r['cf'] || isset( $W['cfirst'][ $h ] );
			$r['cl'] = $r['cl'] || isset( $W['clast'][ $h ] );
			if ( isset( $safe[ $part ] ) ) {
				$r['month'] = $r['month'] || in_array( $part, self::MONTH_NAMES, true );
				continue;
			}
			if ( ! isset( $W['ord'][ $part ] ) && ! isset( $W['cfirst'][ $h ] ) && ! isset( $W['clast'][ $h ] ) && null !== self::stem_known( $part ) ) {
				$r['cls'] = 'safe' === $r['cls'] ? 'ord' : $r['cls'];   // "Invoiced", "Rescheduling": an ordinary word's form
				continue;
			}
			if ( isset( $W['ord'][ $part ] ) ) {
				$r['cls']   = 'safe' === $r['cls'] ? 'ord' : $r['cls'];
				$r['first'] = $r['first'] || isset( $W['first'][ $h ] );
				$r['last']  = $r['last'] || isset( $W['last'][ $h ] );
				continue;
			}
			$r['cls'] = 'name';
		}
		// a short acronym in capitals (YTD, HOA, CA) is not a name, unless it is a name word written in capitals (LEE)
		if ( 'name' === $r['cls'] && mb_strlen( $plain ) <= 3 && $plain === mb_strtoupper( $plain ) ) {
			$r['cls'] = 'safe';
		}
		if ( $r['stop'] ) {
			$r['cf'] = $r['cl'] = $r['first'] = $r['last'] = false;   // "In", "The", "You": never part of a name
		}
		return $r;
	}

	/** How a word is looked up in the name lists (inc/data/privacy-view-words.php keeps them as these hashes). */
	public static function name_hash( $word ) {
		return substr( md5( 'zdz-pv|' . (string) $word ), 0, 12 );
	}

	/** An -ed/-ing/-s form of a listed word ("invoiced", "rescheduling"), or null. Only these endings (an -ly or -er
	 *  "form" would turn some names into words), only on a base of 4+ letters, never a census name. */
	private static function stem_known( $k ) {
		$W    = self::words();
		$safe = self::safe_words();
		foreach ( array( 'ing', 'ed', 'es', 's', 'ies', 'ied' ) as $sfx ) {
			$l = strlen( $sfx );
			if ( strlen( $k ) < $l + 4 || substr( $k, -$l ) !== $sfx ) {
				continue;
			}
			$base = substr( $k, 0, -$l );
			$try  = array( $base, $base . 'e' );
			if ( 'ies' === $sfx || 'ied' === $sfx ) {
				$try[] = $base . 'y';
			}
			if ( strlen( $base ) > 2 && $base[ strlen( $base ) - 1 ] === $base[ strlen( $base ) - 2 ] ) {
				$try[] = substr( $base, 0, -1 );   // "planned" -> plan
			}
			foreach ( $try as $t ) {
				if ( isset( $W['ord'][ $t ] ) || isset( $safe[ $t ] ) ) {
					return $t;
				}
			}
		}
		return null;
	}

	/**
	 * THE NAME RULE, over pieces of text that may be split by markup (HTML tags between pieces; Markdown ** _ ` and
	 * spaces, a no-break space or one line break between words). Capitalised words next to each other are read
	 * together. A word is masked if it is a name (unlisted), or follows a courtesy title, or is an ordinary word that
	 * is also a first name followed by a surname-like word ("Robin Hill"), or "Surname, First"; then any first-name or
	 * surname word, or initial, next to a masked one is masked with it. Masked words next to each other become one
	 * "[name]". $kinds: 'text' (searched), 'inline' (a tag a name may run across: b, i, span, a ...), 'block' (a
	 * break). Returns the pieces, changed.
	 */
	public static function names_over( array $pieces, array $kinds ) {
		$tok = array();
		foreach ( $pieces as $pi => $txt ) {
			if ( 'text' !== ( $kinds[ $pi ] ?? 'text' ) || '' === (string) $txt ) {
				continue;
			}
			// a word starts after anything but a letter, digit or code mark, or right after a URL-encoded space (%20)
			$r = preg_match_all( '/(?:(?<![\p{L}\p{N}_·⟦#])|(?<=%20))\p{Lu}(?:[\p{L}\'’\-]*\p{L})?(?![\p{L}\p{N}_·])/u', (string) $txt, $mm, PREG_OFFSET_CAPTURE );
			if ( false === $r ) {
				throw new RuntimeException( 'name rule could not read the text' );   // not valid UTF-8, or too large: refused
			}
			foreach ( $r ? $mm[0] : array() as $m ) {
				$len = strlen( $m[0] );
				if ( 1 === mb_strlen( $m[0] ) && '.' === substr( (string) $txt, $m[1] + $len, 1 ) ) {
					$len++;   // an initial keeps its full stop
				}
				$tok[] = array( 'p' => $pi, 'o' => $m[1], 'l' => $len, 'w' => substr( (string) $txt, $m[1], $len ) );
			}
		}
		$n = count( $tok );
		if ( 0 === $n ) {
			return $pieces;
		}
		$c = array();
		foreach ( $tok as $i => $t ) {
			$c[ $i ] = self::word_class( $t['w'] );
		}
		// the gap between each word and the next: 'join', 'comma' or '' (a break). A name may run over spaces, a
		// no-break space, one line break, Markdown marks, inline tags, and lower-case particles (de la, van, bin).
		$mark = '(?:[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{2060}*_~`+]|%20)';
		$part = '(?:de|del|della|la|las|los|da|das|do|dos|di|du|van|von|der|den|ter|le|y|bin|ibn|al|el)';
		$gap  = array();
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$a = $tok[ $i ];
			$b = $tok[ $i + 1 ];
			$tags = false;
			if ( $a['p'] === $b['p'] ) {
				$g = substr( (string) $pieces[ $a['p'] ], $a['o'] + $a['l'], $b['o'] - $a['o'] - $a['l'] );
			} else {
				$g  = substr( (string) $pieces[ $a['p'] ], $a['o'] + $a['l'] );
				$ok = true;
				for ( $q = $a['p'] + 1; $q < $b['p']; $q++ ) {
					$k = $kinds[ $q ] ?? 'text';
					if ( 'inline' === $k ) {
						$tags = true;
					} elseif ( 'text' === $k ) {
						$g .= (string) $pieces[ $q ];
					} else {
						$ok = false;
						break;
					}
				}
				if ( ! $ok ) {
					$gap[ $i ] = '';
					continue;
				}
				$g .= substr( (string) $pieces[ $b['p'] ], 0, $b['o'] );
			}
			if ( 'hon' === $c[ $i ]['cls'] && '.' === substr( $g, 0, 1 ) ) {
				$g = substr( $g, 1 );   // "Mr. Quinn"
			}
			$short = strlen( $g ) <= 40 && substr_count( $g, "\n" ) <= 1;
			if ( $short && ( ( '' === $g && $tags ) || ( '' !== $g && preg_match( '/^' . $mark . '+(?:' . $part . $mark . '+){0,3}$/u', $g ) ) ) ) {
				$gap[ $i ] = 'join';
			} elseif ( $short && false === strpos( $g, "\n" ) && preg_match( '/^' . $mark . '*,' . $mark . '+$/u', $g ) ) {
				$gap[ $i ] = 'comma';
			} else {
				$gap[ $i ] = '';
			}
		}
		// a place written out (two or three words, from the coder's places and the safe phrases) is one safe thing,
		// never part of a name
		$places = self::places();
		$place  = array_fill( 0, $n, false );
		for ( $i = 0; $i < $n; $i++ ) {
			$bare   = function ( $w ) {
				return mb_strtolower( (string) preg_replace( '/[\'’]s$/u', '', $w ) );   // a place's possessive
			};
			$phrase = $bare( $tok[ $i ]['w'] );
			for ( $j = $i + 1; $j < $n && $j <= $i + 3 && 'join' === $gap[ $j - 1 ]; $j++ ) {
				$phrase .= ' ' . $bare( $tok[ $j ]['w'] );
				if ( isset( $places[ $phrase ] ) ) {
					for ( $q = $i; $q <= $j; $q++ ) {
						$place[ $q ] = true;
					}
				}
			}
		}
		$m    = array_fill( 0, $n, false );
		$bond = array_fill( 0, $n, false );   // $bond[i]: words i and i+1 belong to one name
		$namey = function ( $i ) use ( &$c, &$place ) {   // may be part of a person's name
			return ! $place[ $i ] && ( 'name' === $c[ $i ]['cls'] || $c[ $i ]['cf'] || $c[ $i ]['cl'] || $c[ $i ]['month'] );
		};
		for ( $i = 0; $i < $n; $i++ ) {
			if ( 'name' === $c[ $i ]['cls'] && ! $place[ $i ] ) {
				$m[ $i ] = true;
			}
		}
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$x = $c[ $i ];
			$y = $c[ $i + 1 ];
			if ( 'join' === $gap[ $i ] ) {
				if ( 'hon' === $x['cls'] && 'initial' !== $y['cls'] && ! $place[ $i + 1 ] ) {
					$m[ $i + 1 ] = true;   // "Mr. Quinn", "Dr Quinn"
				}
				if ( $x['greet'] && ! $place[ $i + 1 ] && ( 'name' === $y['cls'] || $y['cf'] ) ) {
					$m[ $i + 1 ] = true;   // "Hi Morgan"
				}
				// a first name and a surname-like word: "Robin Hill", "Robin Price" (both ordinary words too). A month
				// starts one only before a word that is not one of the safe vocabulary ("May Quinn", not "May Sales").
				if ( ! $place[ $i ] && ! $place[ $i + 1 ] && $x['cf'] && ( 'name' === $y['cls'] || $y['cl'] || $y['cf'] )
					&& ( ! $x['month'] || 'safe' !== $y['cls'] ) && ! ( 'safe' === $x['cls'] && ! $x['month'] && 'safe' === $y['cls'] ) ) {
					$m[ $i ] = $m[ $i + 1 ] = true;
				}
			} elseif ( 'comma' === $gap[ $i ] ) {
				if ( $x['greet'] && ! $place[ $i + 1 ] && ( 'name' === $y['cls'] || $y['cf'] ) ) {
					$m[ $i + 1 ] = true;   // "Thanks, Morgan"
				}
				$lastish  = 'name' === $x['cls'] || $x['cl'];
				$firstish = 'name' === $y['cls'] || $y['cf'];
				if ( ! $place[ $i ] && ! $place[ $i + 1 ] && $lastish && $firstish && ( 'name' === $x['cls'] || 'name' === $y['cls'] || ( $x['cl'] && $y['cf'] && 'safe' !== $x['cls'] ) ) ) {
					$m[ $i ] = $m[ $i + 1 ] = true;
					$bond[ $i ] = true;   // "Quinn, Alex"
				}
			}
		}
		// grow each masked word over its neighbours that can be part of a name (and initials)
		do {
			$grew = false;
			for ( $i = 0; $i < $n; $i++ ) {
				if ( ! $m[ $i ] ) {
					continue;
				}
				foreach ( array( $i - 1, $i + 1 ) as $j ) {
					if ( $j < 0 || $j >= $n || $m[ $j ] || 'join' !== $gap[ min( $i, $j ) ] ) {
						continue;
					}
					if ( 'initial' === $c[ $j ]['cls'] || $namey( $j ) ) {
						$m[ $j ] = $grew = true;
					}
				}
			}
		} while ( $grew );
		for ( $i = 0; $i < $n - 1; $i++ ) {
			if ( $m[ $i ] && $m[ $i + 1 ] && 'join' === $gap[ $i ] ) {
				$bond[ $i ] = true;
			}
		}
		// edits, per piece: the first word of a group becomes [name]; the rest, and what lies between, go (Markdown
		// marks are kept so bold stays balanced; tags are untouched)
		$strip = function ( $g ) {
			return (string) preg_replace( '/[^*_~`]/u', '', str_replace( '%20', '', (string) $g ) );
		};
		$edits = array();
		for ( $i = 0; $i < $n; $i++ ) {
			if ( ! $m[ $i ] ) {
				continue;
			}
			$t     = $tok[ $i ];
			$start = ! ( $i > 0 && $bond[ $i - 1 ] );
			$edits[ $t['p'] ][] = array( $t['o'], $t['l'], $start ? self::NAME_GONE : '' );
			if ( $i < $n - 1 && $bond[ $i ] ) {
				$b    = $tok[ $i + 1 ];
				$from = $t['o'] + $t['l'];
				if ( $t['p'] === $b['p'] ) {
					$g = substr( (string) $pieces[ $t['p'] ], $from, $b['o'] - $from );
					$edits[ $t['p'] ][] = array( $from, strlen( $g ), $strip( $g ) );
				} else {
					$g = substr( (string) $pieces[ $t['p'] ], $from );
					$edits[ $t['p'] ][] = array( $from, strlen( $g ), $strip( $g ) );
					for ( $q = $t['p'] + 1; $q < $b['p']; $q++ ) {
						if ( 'text' === ( $kinds[ $q ] ?? 'text' ) ) {
							$edits[ $q ][] = array( 0, strlen( (string) $pieces[ $q ] ), $strip( $pieces[ $q ] ) );
						}
					}
					$g = substr( (string) $pieces[ $b['p'] ], 0, $b['o'] );
					$edits[ $b['p'] ][] = array( 0, strlen( $g ), $strip( $g ) );
				}
			}
		}
		foreach ( $edits as $pi => $list ) {
			usort( $list, function ( $x, $y ) { return $y[0] - $x[0]; } );
			$txt = (string) $pieces[ $pi ];
			foreach ( $list as $e ) {
				$txt = substr( $txt, 0, $e[0] ) . $e[2] . substr( $txt, $e[0] + $e[1] );
			}
			$pieces[ $pi ] = $txt;
		}
		return $pieces;
	}

	const NAME_WINDOW = 65536;   // the name rule reads at most this much at once (memory stays bounded)

	/** The name rule over one text, in windows cut only where no name can run on (after . ! ? ; : | or a line end). */
	public static function strict_names( $t ) {
		$t   = (string) $t;
		$out = '';
		while ( strlen( $t ) > self::NAME_WINDOW ) {
			$win = substr( $t, 0, self::NAME_WINDOW );
			if ( ! preg_match_all( '/[.!?;:|\n][ \t\r\n]/', $win, $mm, PREG_OFFSET_CAPTURE ) ) {
				throw new RuntimeException( 'a text too long to read for names' );   // refused
			}
			$cut  = end( $mm[0] )[1] + 2;
			$out .= (string) self::names_over( array( substr( $t, 0, $cut ) ), array( 'text' ) )[0];
			$t    = substr( $t, $cut );
		}
		return $out . (string) self::names_over( array( $t ), array( 'text' ) )[0];
	}

	/** The name rule over HTML's pieces, a batch at a time: a batch ends at a block tag once it holds 32 KB of text. */
	private static function names_batched( array $texts, array $kinds ) {
		$batch = array();
		$size  = 0;
		$flush = function () use ( &$batch, &$size, &$texts, $kinds ) {
			if ( ! $batch ) {
				return;
			}
			$sub = array();
			$k   = array();
			foreach ( $batch as $i ) {
				$sub[] = $texts[ $i ];
				$k[]   = $kinds[ $i ];
			}
			$sub = self::names_over( $sub, $k );
			foreach ( $batch as $j => $i ) {
				$texts[ $i ] = $sub[ $j ];
			}
			$batch = array();
			$size  = 0;
		};
		foreach ( $texts as $i => $t ) {
			$kind = $kinds[ $i ] ?? 'block';
			if ( 'text' === $kind && strlen( (string) $t ) > self::NAME_WINDOW ) {
				$flush();
				$texts[ $i ] = self::strict_names( $t );   // one very long text: windows of its own
				continue;
			}
			if ( 'block' === $kind && $size > 32768 ) {
				$flush();
				continue;
			}
			$batch[] = $i;
			$size   += 'text' === $kind ? strlen( (string) $t ) : 0;
		}
		$flush();
		return $texts;
	}

	/** Every pass but the name rule (HTML runs the name rule across its text pieces itself). */
	private static function prose_base( $s ) {
		$t = self::from_codes( self::unmask_ordinary( (string) $s, self::coder()->encode( (string) $s ) ) );
		$t = self::staff_full( $t );
		$t = self::staff_prose( $t );
		$t = self::staff_logins( $t );
		$t = self::street_pass( $t );
		$t = (string) preg_replace_callback( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', function ( $m ) {
			return self::email( $m[0] );
		}, $t );
		$t = self::client_ids( $t );   // before the document pass, which would read "customer #30045" as an invoice
		return self::doc_pass( $t, (bool) preg_match( '/\s/u', trim( $t ) ) );
	}

	/** The billing and CRM systems whose name before "client" or "customer" makes the number after it that system's id
	 *  for a customer. Lower case. A business on another system names it on zdz_privacy_view_system_words. */
	const SYSTEM_WORDS = array( 'freshbooks', 'nutshell', 'quickbooks', 'stripe', 'billing', 'crm' );
	/** Words after a number that make it a count or a size, not an id ("customer: 120 units"). The business's own
	 *  product words count too (its catalog and its vocabulary). */
	const UNIT_WORDS_RE = '/^(?:sq|sqm|square|m2|m²|ft|feet|foot|in|inch|inches|yd|yards?|m|mm|cm|km|metres?|meters?|kg|kilos?|g|lbs?|pounds?|litres?|liters?|dollars?|euros?|units?|jobs?|days?|hours?|minutes?|miles?|percent|of|items?|pieces?)$/iu';

	/** SYSTEM_WORDS and what a plugin adds: lower-case words of letters and digits. */
	public static function system_words() {
		$f   = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_privacy_view_system_words', self::SYSTEM_WORDS ) : self::SYSTEM_WORDS;
		$out = array();
		foreach ( array_merge( self::SYSTEM_WORDS, is_array( $f ) ? $f : array() ) as $w ) {
			$w = is_string( $w ) ? mb_strtolower( trim( $w ) ) : '';
			if ( preg_match( '/\A[\p{L}\p{N}]{2,30}\z/u', $w ) && count( $out ) < 40 ) {
				$out[ $w ] = true;
			}
		}
		return array_keys( $out );
	}

	/** Is this word one that counts things: a unit, or one of the business's own product words (singular or plural)? */
	private static function counts_things( $word ) {
		$w = mb_strtolower( trim( (string) $word ) );
		if ( '' === $w ) {
			return false;
		}
		if ( preg_match( self::UNIT_WORDS_RE, $w ) ) {
			return true;
		}
		$own = self::vocabulary()['words'] + self::product_terms()['words'];
		return isset( $own[ $w ] ) || ( 's' === substr( $w, -1 ) && isset( $own[ substr( $w, 0, -1 ) ] ) );
	}

	/**
	 * A billing or CRM system's id for a customer, written in prose, is a customer: "billing client 1000042", "client
	 * ID: 20031", "customer #30045", "client no. 4005" keep their words and the number becomes a customer code (the one
	 * a coded customer with no ledger name gets). A bare "client 12345" counts at five digits or more (a count beside
	 * the word is shorter); a system's name before the word makes any number one; a count or a size is never one, nor
	 * is a year. It runs before the document pass, which would read "customer #30045" as an invoice.
	 */
	private static function client_ids( $t ) {
		$sys = implode( '|', array_map( function ( $w ) { return preg_quote( $w, '/' ); }, self::system_words() ) );
		$r   = preg_replace_callback( '/(?<![\p{L}\p{N}_])((?:(?i:' . $sys . ')\s+)?(?i:client|customer))(\s*(?:[_\s]?(?i:id|no\.?|number|num\.?)\s*[:#]?\s*|[:#]\s*|\s+#?))(\d{3,12})(?![\d%\p{L}_]|[.,]\d)(\s+\p{L}+)?/u', function ( $m ) {
			$system = ! preg_match( '/\A(?i:client|customer)\z/', $m[1] );
			$named  = (bool) preg_match( '/(?i:id|no|number|num)|#/', $m[2] );   // a ":" alone is not a name for the number
			if ( ( ! $system && ! $named && strlen( $m[3] ) < 5 ) || ( ! $named && isset( $m[4] ) && self::counts_things( $m[4] ) ) ) {
				return $m[0];   // a count or a size beside the word ("customer: 120 units", "client 10000 sq ft")
			}
			if ( 4 === strlen( $m[3] ) && (int) $m[3] >= 1990 && (int) $m[3] <= 2099 ) {
				return $m[0];   // a year
			}
			$gap = str_replace( '#', '', $m[2] );
			$gap = '' === $gap ? ' ' : $gap;   // "customer#30045": a space where the "#" was
			return $m[1] . $gap . self::tag( 'Cust' ) . self::code( 'customer-id', ltrim( $m[3], '0' ) ) . ( $m[4] ?? '' );
		}, (string) $t );
		return is_string( $r ) ? $r : (string) $t;
	}

	/** The team member whose login or display name this whole value is, in any case, or 0. */
	public static function staff_exact( $s, $namey = false ) {
		$k = strtolower( trim( (string) $s ) );
		if ( mb_strlen( $k ) < 2 ) {
			return 0;
		}
		$st = self::staff_names();
		foreach ( $st['full'] as $n => $id ) {
			if ( strtolower( $n ) === $k ) {
				return (int) $id;   // a full name ("Alex Example", in any case)
			}
		}
		// one word -- a login or a one-word name ("alex") -- only when it is not an ordinary or safe word (a login or a
		// name "Admin", "Sales", "Office" leaves the labels "Admin", "Sales", "Office" alone)
		if ( false !== strpos( $k, ' ' ) || ( ! $namey && self::plain_word( $k ) ) ) {
			return 0;   // in a field that holds a person's name ($namey), "robin" is Robin even though "robin" is a word
		}
		if ( isset( $st['logins'][ $k ] ) ) {
			return (int) $st['logins'][ $k ];
		}
		// a one-word display name. A plain word ("admin") reaches this point only in a field that holds a person's name
		// ($namey, above): there it is the person whose display name it is; in prose it stays a word
		if ( isset( $st['display'][ $k ] ) ) {
			return (int) $st['display'][ $k ];
		}
		foreach ( $st['words'] as $w => $id ) {
			if ( strtolower( $w ) === $k ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/** An ordinary, safe or common word (never taken for a team member on its own). */
	private static function plain_word( $k ) {
		$k = strtolower( (string) $k );
		return isset( self::words()['ord'][ $k ] ) || isset( self::safe_words()[ $k ] ) || in_array( $k, self::common_words(), true );
	}

	/** "@login" of a team member (the chat's roster, mentions in messages) is "@" and their pseudonym. An e-mail address
	 *  is not read here (a word character before the "@"). */
	private static function staff_logins( $t, $bare = true ) {
		$lg = self::staff_names()['logins'] ?? array();
		if ( ! $lg ) {
			return (string) $t;
		}
		$t = (string) preg_replace_callback( '/(?<![\w.@])@([A-Za-z0-9_.\-]{2,60})(?![\w@])/u', function ( $m ) use ( $lg ) {
			$k = strtolower( rtrim( $m[1], '.-' ) );
			return isset( $lg[ $k ] ) ? '@' . self::staff( $lg[ $k ] ) . substr( $m[1], strlen( rtrim( $m[1], '.-' ) ) ) : $m[0];
		}, (string) $t );
		// a login written bare ("ask jdoe"), in any case, as a whole word -- four characters or more and not an ordinary
		// or safe word (a login "sales" stays the word "sales"). Prose only: in machine values and code (the quiet
		// pass) an id or a slug that equals a login is left alone.
		if ( ! $bare ) {
			return $t;
		}
		$words = array();
		foreach ( $lg as $k => $id ) {
			if ( mb_strlen( $k ) >= 4 && ! self::plain_word( $k ) ) {
				$words[ $k ] = $id;
			}
		}
		if ( ! $words ) {
			return $t;
		}
		uksort( $words, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		$alt = implode( '|', array_map( function ( $k ) { return preg_quote( $k, '/' ); }, array_keys( $words ) ) );
		return (string) preg_replace_callback( '/(?<![\w.@\/·-])(' . $alt . ')(?![\w@·-])/iu', function ( $m ) use ( $words ) {
			return self::staff( $words[ strtolower( $m[1] ) ] );
		}, $t );
	}

	/** Prose: every pass. */
	public static function mask_prose( $s ) {
		if ( 0 === self::$depth ) {
			return self::guarded( __FUNCTION__, func_get_args() );   // the business's own names, addresses and the account's own name are set aside first
		}
		$s = (string) $s;
		if ( '' === trim( $s ) ) {
			return $s;
		}
		if ( preg_match( '/<(?:[a-z][a-z0-9]*)\b[^>]*>/i', $s ) && preg_match( '/<\/[a-z]/i', $s ) ) {
			return self::mask_html( $s, false );   // HTML inside a field: its tags kept, its text masked across them
		}
		return self::strict_names( self::prose_base( $s ) );
	}

	/** A team member's own name words, case-sensitive (a quiet field may hold "Alex"). */
	private static function staff_words( $t ) {
		foreach ( self::staff_names()['words'] as $w => $id ) {
			if ( false !== strpos( $t, $w ) ) {
				$t = (string) preg_replace( '/(?<![\p{L}\p{N}·])' . preg_quote( $w, '/' ) . '(?![\p{L}\p{N}])/u', self::staff( $id ), $t );
			}
		}
		return $t;
	}

	/** Quiet (machine fields and script code): known names, team names and e-mails only; ids, colours, URLs stay whole. */
	public static function mask_quiet( $s ) {
		if ( 0 === self::$depth ) {
			return self::guarded( __FUNCTION__, func_get_args() );   // the business's own names, addresses and the account's own name are set aside first
		}
		$s = (string) $s;
		if ( '' === trim( $s ) ) {
			return $s;
		}
		$coder = self::coder();
		$t = method_exists( $coder, 'names_only' ) ? self::unmask_ordinary( $s, $coder->names_only( $s ) ) : $s;
		$t = (string) preg_replace_callback( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', function ( $m ) {
			return self::email( $m[0] );
		}, $t );
		return self::staff_logins( self::staff_words( self::staff_full( self::from_codes( $t ) ) ), false );   // "@login" too (bare logins: prose only)
	}

	/**
	 * What is never masked: the business's own names and web addresses (from its Business Profile; the site's host is
	 * not a customer, even where a ledger row carries the business's name), the site's title and tagline, and the
	 * Privacy View account's own name. A filter adds more. Longest first.
	 */
	public static function own_names() {
		if ( null !== self::$own ) {
			return self::$own;
		}
		$n = array();
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			foreach ( array( 'identity.legal_name', 'identity.trading_name', 'identity.short_name', 'brand.pwa.name', 'brand.pwa.short_name', 'web.app_domain', 'web.marketing_domain' ) as $k ) {
				$n[] = (string) ZDZ_Business_Profile::get( $k, '' );
			}
			foreach ( (array) ZDZ_Business_Profile::get( 'identity.former_names', array() ) as $f ) {
				$n[] = is_scalar( $f ) ? (string) $f : '';
			}
		}
		foreach ( array( 'home_url', 'site_url' ) as $f ) {
			if ( function_exists( $f ) ) {
				$h = (string) parse_url( (string) $f( '/' ), PHP_URL_HOST );
				if ( '' !== $h ) {
					$n[] = $h;
					$n[] = preg_replace( '/^www\./', '', $h );
				}
			}
		}
		if ( function_exists( 'get_bloginfo' ) ) {
			$n[] = (string) get_bloginfo( 'name' );
			$n[] = (string) get_bloginfo( 'description' );
		}
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $uid > 0 && self::is_on( $uid ) && function_exists( 'get_userdata' ) ) {
			$u = get_userdata( $uid );   // the account's own name: it is not a customer, and it is not hidden from itself
			if ( $u ) {
				foreach ( array( 'display_name', 'user_login', 'nickname' ) as $k ) {
					$n[] = (string) ( $u->$k ?? '' );
				}
				$n[] = trim( get_user_meta( $uid, 'first_name', true ) . ' ' . get_user_meta( $uid, 'last_name', true ) );
				// its first or last name ON ITS OWN is kept only as a whole field value (mask_value), never in text:
				// set aside in text, the account's first name would let a customer's surname next to it through
			}
		}
		if ( class_exists( 'ZDZ_Private' ) && method_exists( 'ZDZ_Private', 'own_names' ) ) {
			$n = array_merge( $n, (array) ZDZ_Private::own_names() );   // the names the coder never codes
		}
		$n = array_merge( $n, (array) apply_filters( 'zdz_privacy_view_own_names', array() ) );
		$n = array_values( array_unique( array_filter( array_map( 'trim', $n ), function ( $x ) {
			return mb_strlen( $x ) >= 3;
		} ) ) );
		usort( $n, function ( $a, $b ) { return mb_strlen( $b ) - mb_strlen( $a ); } );
		return self::$own = $n;
	}

	/** Is this whole value the Privacy View account's own first or last name? */
	private static function own_field( $s ) {
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $uid <= 0 || ! self::is_on( $uid ) || mb_strlen( trim( $s ) ) < 2 ) {
			return false;
		}
		foreach ( array( 'first_name', 'last_name' ) as $k ) {
			if ( 0 === strcasecmp( trim( $s ), trim( (string) get_user_meta( $uid, $k, true ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Marks a business uses for made-up records (a test project, a demo customer), kept exactly as written: never a
	 * customer. None by default; a business adds its own on zdz_privacy_view_kept_marks. Each is two words or more, so
	 * a single word that marks a test record can never let a real name through.
	 */
	private static function kept_marks() {
		$out = array();
		foreach ( (array) ( function_exists( 'apply_filters' ) ? apply_filters( 'zdz_privacy_view_kept_marks', array() ) : array() ) as $m ) {
			$w = preg_split( '/\s+/u', trim( (string) $m ), -1, PREG_SPLIT_NO_EMPTY );
			if ( count( $w ) >= 2 && count( $w ) <= 4 && mb_strlen( implode( ' ', $w ) ) <= 40 ) {
				$out[] = $w;
			}
		}
		return $out;
	}

	/** Set the own names aside as private-use placeholders (no pass reads them: not letters, not digits). */
	private static function protect( $s ) {
		if ( ! is_string( $s ) || '' === $s ) {
			return $s;
		}
		foreach ( self::kept_marks() as $words ) {
			if ( false === stripos( $s, $words[0] ) ) {
				continue;
			}
			// the words together only, as written or in capitals ("Demo Record", "DEMO RECORD")
			$pat = implode( '[ \x{00A0}]', array_map( function ( $w ) { return '(?:' . preg_quote( $w, '/' ) . '|' . preg_quote( mb_strtoupper( $w ), '/' ) . ')'; }, $words ) );
			$s   = (string) preg_replace_callback( '/(?<![\p{L}\p{N}])' . $pat . '(?![\p{L}\p{N}])/u', function ( $m ) {
				return self::hold( $m[0] );
			}, $s );
		}
		foreach ( self::own_names() as $name ) {
			if ( false === stripos( $s, $name ) ) {
				continue;
			}
			$host = false !== strpos( $name, '.' ) && false === strpos( $name, ' ' );   // a host: also inside %2F-encoded URLs
			$s = (string) preg_replace_callback( '/' . ( $host ? '' : '(?<![\p{L}\p{N}])' ) . preg_quote( $name, '/' ) . ( $host ? '' : '(?![\p{L}\p{N}])' ) . '/iu', function ( $m ) use ( $s, $host ) {
				// a host that is the domain of an e-mail address stays: the address is masked whole ("jordan@" would
				// otherwise name a team member)
				if ( $host && preg_match( '/@[A-Za-z0-9.\-]*$/', substr( $s, max( 0, $m[0][1] - 80 ), min( 80, $m[0][1] ) ) ) ) {
					return $m[0][0];
				}
				return self::hold( $m[0][0] );
			}, $s, -1, $count, PREG_OFFSET_CAPTURE );
		}
		return $s;
	}

	/** One placeholder per original (a private-use character: no pass reads it), given back by unprotect(). */
	private static function hold( $orig ) {
		$k = array_search( $orig, self::$kept, true );
		if ( false === $k ) {
			$k = mb_chr( 0xE010 + count( self::$kept ), 'UTF-8' );
			self::$kept[ $k ] = $orig;
		}
		return $k;
	}

	/**
	 * The one administrator the account may message (dm_partner) is shown by their first name where the account is
	 * writing to them or reading their thread, and nowhere else. This runs AFTER masking and turns back only THEIR OWN
	 * team pseudonym (setting the name aside before masking would let a customer whose surname is the partner's first
	 * name through whole), so nothing else can come out of it:
	 *   - every reply to a direct-message request (the messaging app's list, thread, new messages and post for the
	 *     account, which it narrows to that one thread; the docked thread page; the chat's send);
	 *   - in the chat, inside a message card addressed to them ([ZIM_DM_DRAFT:@your-admin] … [/ZIM_DM_DRAFT]) and right
	 *     after "DM to", "message to", "messaged".
	 * Never where the pseudonym stands next to another pseudonym, "[name]" or a capitalised word (part of someone
	 * else's name), after "@" (a login), or as a login field's value.
	 */
	private static function restore_dm( $s ) {
		if ( ! is_string( $s ) || '' === $s ) {
			return $s;
		}
		$who = self::dm_who();
		if ( ! $who || ( null === self::$view && ! self::labels() ) ) {
			return $s;   // no partner, or no code was made in this reply (so theirs cannot be in it)
		}
		$code = self::staff( $who['id'] );
		if ( false === strpos( $s, $code ) ) {
			return $s;
		}
		$swap = function ( $t ) use ( $code, $who ) {
			$r = preg_replace_callback( '/' . preg_quote( $code, '/' ) . '(?![0-9A-Za-z_])/u', function ( $m ) use ( $t, $who ) {
				// windows cut on character boundaries, and a check that cannot run (preg_match false) keeps the code
				$from   = max( 0, $m[0][1] - 60 );
				$before = (string) preg_replace( '/^[\x80-\xBF]+/', '', substr( $t, $from, $m[0][1] - $from ) );   // no half character at the far end
				$after  = (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', (string) substr( $t, $m[0][1] + strlen( $m[0][0] ), 60 ) );
				$w      = array();
				$checks = array(
					preg_match( '/(?:@|"[a-z_]*(?:login|user_?name)"\s*:\s*")$/i', $before ),
					preg_match( '/(?:\[name\]|·[0-9A-Z]{4}[0-9A-Z_])[\s\x{00A0}]+$/u', $before ),
					preg_match( '/^[\s\x{00A0}]+(?:\[name\]|\p{Lu})/u', $after ),   // any capital, in any script
				);
				$lead = preg_match( '/(\p{Lu}\p{Ll}+)[\s\x{00A0}]+$/u', $before, $w );
				if ( in_array( 1, $checks, true ) || in_array( false, $checks, true ) || false === $lead
					|| ( 1 === $lead && ! in_array( strtolower( $w[1] ), self::DM_LEAD_WORDS, true ) ) ) {
					return $m[0][0];
				}
				return $who['name'];
			}, $t, -1, $n, PREG_OFFSET_CAPTURE );
			return is_string( $r ) ? $r : $t;   // text a pattern cannot read stays as masked
		};
		if ( self::dm_request() ) {
			return $swap( $s );
		}
		$r = preg_replace_callback( '/\[ZIM_DM(?:_DRAFT)?:\s*@\s*' . preg_quote( self::DM_TARGET, '/' ) . '\s*\][\s\S]*?\[\\\\?\/ZIM_DM(?:_DRAFT)?\]/u', function ( $m ) use ( $swap ) {
			return $swap( $m[0] );
		}, $s );
		$s = is_string( $r ) ? $r : $s;
		$r = preg_replace_callback( '/(?<![\p{L}\p{N}])(?:(?:DM|direct message|message)\s+(?:to|for)|messaged)\s+' . preg_quote( $code, '/' ) . '(?![0-9A-Za-z_])(?!\s+(?:\[name\]|\p{Lu}))/u', function ( $m ) use ( $code, $who ) {
			return str_replace( $code, $who['name'], $m[0] );
		}, $s );
		return is_string( $r ) ? $r : $s;
	}

	/** A capitalised word before the partner's pseudonym that is not part of a name (a greeting or an ordinary word). */
	const DM_LEAD_WORDS = array( 'hi', 'hey', 'hello', 'dear', 'thanks', 'thank', 'ok', 'okay', 'yes', 'no', 'sure', 'morning',
		'afternoon', 'evening', 'for', 'to', 'from', 'with', 'and', 'or', 'ask', 'tell', 'email', 'call', 'text', 'message', 'dm', 'cc' );

	/** The partner: their user id and the name they are shown by (first name, else display name), or null. */
	private static function dm_who() {
		$n = self::dm_names();
		if ( ! $n ) {
			return null;
		}
		return array( 'id' => (int) $n['id'], 'name' => (string) $n['name'] );
	}

	/** What the chat's message card names the partner. */
	const DM_TARGET = 'your-admin';

	/** The direct-message partner: array( 'id', 'name' => first name (else display name) ), or array() without one. */
	public static function dm_names() {
		if ( null !== self::$dm_names ) {
			return self::$dm_names;
		}
		$out = array();
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$p   = ( $uid > 0 && self::is_on( $uid ) ) ? self::dm_partner() : 0;
		if ( $p > 0 && function_exists( 'get_userdata' ) ) {
			$u = get_userdata( $p );
			if ( $u ) {
				$first = trim( (string) get_user_meta( $p, 'first_name', true ) );
				$name  = mb_strlen( $first ) >= 2 ? $first : trim( (string) ( $u->display_name ?? '' ) );
				if ( '' !== $name && ! preg_match( '/[@\/<>"]/', $name ) ) {
					$out = array( 'id' => $p, 'name' => $name );
				}
			}
		}
		return self::$dm_names = $out;
	}

	/** Is this request one of the account's direct-message requests (its whole reply is the thread with the partner)? */
	public static function dm_request() {
		if ( 'ajax' === self::request_kind() ) {
			$a = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';   // phpcs:ignore
			return in_array( $a, self::DM_AJAX, true );
		}
		if ( 'front' !== self::request_kind() ) {
			return false;   // a REST route carrying these keys is not the thread page
		}
		$keys = array_keys( (array) $_GET );   // phpcs:ignore
		return ! array_diff( self::DM_PAGE_KEYS, $keys ) && ! array_diff( $keys, array_merge( self::DM_PAGE_KEYS, array( 'cb' ) ) );
	}

	private static function unprotect( $s ) {
		return is_string( $s ) && self::$kept ? strtr( $s, self::$kept ) : $s;
	}

	/** The outermost masking call: protect, mask, restore. Nested calls mask only. */
	private static function guarded( $fn, array $args ) {
		self::$depth++;
		self::$kept = array();
		try {
			if ( isset( $args[0] ) && is_string( $args[0] ) ) {
				$args[0] = self::protect( $args[0] );
			}
			$out = self::restore_dm( self::unprotect( call_user_func_array( array( __CLASS__, $fn ), $args ) ) );
			self::shown( $fn, $out );
			return $out;
		} finally {
			self::$depth--;
			self::$kept = array();
		}
	}

	/**
	 * A whole reply was masked under this request's tag -- say so (zdz_privacy_view_shown, with the tag), so the app
	 * that knows which chat it belongs to can keep it: only a code under a tag that chat was shown is ever read back.
	 * Nothing if no code was made.
	 */
	private static function shown( $fn, $out ) {
		if ( null === self::$view || self::labels() || ! in_array( $fn, array( 'mask_response', 'mask_json_text' ), true ) || ! function_exists( 'do_action' )
			|| ! is_string( $out ) || false === strpos( $out, self::$view . self::MID ) ) {
			return;   // no pseudonym under this tag in what is sent
		}
		try {
			do_action( 'zdz_privacy_view_shown', self::$view );
		} catch ( \Throwable $e ) {
			unset( $e );   // a listener's trouble never changes what is sent
		}
	}

	/** Already a pseudonym (a second pass over masked output leaves it as it is). */
	public static function is_pseudonym( $s ) {
		$s = trim( (string) $s );
		return self::NAME_GONE === $s || '[private]' === $s || '[gate code sealed]' === $s
			|| 1 === preg_match( '/^(?:Cust|Staff|Inv|Est|Photo|email|phone|street|[' . self::VIEW_ABC . '][' . strtolower( self::VIEW_ABC ) . ']{3})·[0-9A-Z]{4}[0-9A-Z_]$/u', $s );
	}

	/* ================================================================== 2. by field */

	/**
	 * A machine value (a URL, a slug, a file name, an id, a token). Read URL-decoded: one with spaces in it is text
	 * and gets the full pass ("?customer=Alex%20Quinn"); otherwise each capitalised word inside it
	 * ("Alex_Quinn_Estimate.pdf", "AlexQuinn") is put through the word rule; then known names, team, e-mails and invoice
	 * numbers. Lower-case slugs are what they are (a known limit: a name written in lower case is not a name).
	 */
	public static function mask_machine( $v, $docs = true ) {
		if ( 0 === self::$depth ) {
			return self::guarded( __FUNCTION__, func_get_args() );   // the business's own names, addresses and the account's own name are set aside first
		}
		if ( is_string( $v ) && preg_match( '#^((?:[a-z][a-z0-9+.\-]*:)?//[^/?\#\s]+)(.*)$#is', $v, $um ) ) {
			// a URL: its scheme and host are kept (a host is a site, not a person); the rest is read
			// a link carries invoice numbers in its path or query ("?doc=1234"): any number the ledger knows is masked
			return $um[1] . ( '' === $um[2] ? '' : self::doc_pass( self::mask_machine( $um[2], $docs ), $docs ) );
		}
		$v = (string) $v;
		if ( '' === trim( $v ) ) {
			return $v;
		}
		// a link written %-encoded ("?to=alex%40example.com") has each encoded piece -- a path segment, a key, a value,
		// the fragment -- read decoded, masked, and written back encoded, so the link still works. Then the whole link
		// is read decoded (machine_tail()) over the result, so this step can only add to what is hidden, never take
		// from it (read piece by piece alone, an e-mail in the path beside an encoded value would get through)
		if ( preg_match( '/%[0-9A-Fa-f]{2}|\+/', $v ) && ! preg_match( '/\s/u', $v ) ) {
			$v = self::encoded_parts( $v, $docs );
		}
		return self::machine_tail( $v, $docs );
	}

	/** Each %-encoded piece of a link, decoded, masked (text or a phone: the full pass), re-encoded. */
	private static function encoded_parts( $v, $docs ) {
		$parts = preg_split( '/([\/?&=#;])/', (string) $v, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return (string) $v;
		}
		$query = false;
		foreach ( $parts as $i => $p ) {
			if ( '?' === $p || '#' === $p ) {
				$query = '?' === $p;
				continue;
			}
			if ( 1 === strlen( $p ) && false !== strpos( '/&=;', $p ) ) {
				continue;
			}
			if ( ! preg_match( '/%[0-9A-Fa-f]{2}/', $p ) && ! ( $query && false !== strpos( $p, '+' ) ) ) {
				continue;
			}
			$dv    = $query ? urldecode( $p ) : rawurldecode( $p );
			$nd    = preg_match_all( '/\d/', $dv );
			$phone = preg_match( '/^\+?[\d\s().\-]+$/', $dv ) && $nd >= 7 && $nd <= 15;
			$mv    = ( $phone || preg_match( '/\s/u', $dv ) ) ? self::mask_prose( $dv ) : self::mask_machine( $dv, $docs );
			if ( $mv !== $dv ) {
				$parts[ $i ] = rawurlencode( (string) $mv );
			}
		}
		return implode( '', $parts );
	}

	/** The reading of a machine value below the address: its whole text decoded first. */
	private static function machine_tail( $v, $docs ) {
		$d = ( false !== strpos( $v, '%' ) || false !== strpos( $v, '+' ) ) ? rawurldecode( str_replace( '+', ' ', $v ) ) : $v;
		if ( preg_match( '/\s/u', $d ) ) {
			$p = self::mask_prose( $d );
			return $p === $d ? $v : $p;
		}
		// words inside it ("Alex_Quinn_Estimate.pdf", "AlexQuinn"): a file name, or a value with no digits -- never a random
		// token (a key, a nonce: its capitals are not words)
		$wordy = preg_match( '/\.[A-Za-z0-9]{2,5}$/', $d ) || ! preg_match( '/\d/', $d );
		$t = ! $wordy ? $d : (string) preg_replace_callback( '/\p{Lu}\p{Ll}{2,}(?![\p{Ll}])/u', function ( $m ) {   // "AlexQuinn": both
			return 'name' === self::word_class( $m[0] )['cls'] ? self::NAME_GONE : $m[0];
		}, $d );
		if ( $t !== $d ) {
			$v = $t;
		}
		// a query value that is a team member's login or name ("?u=jdoe") is their pseudonym
		if ( false !== strpos( $v, '=' ) ) {
			$v = (string) preg_replace_callback( '/([?&][A-Za-z0-9_\-\[\]]+=)([^&#\s]+)/', function ( $q ) {
				$sid = self::staff_exact( rawurldecode( $q[2] ) );   // not a word ("?view=sales" stays)
				return $sid > 0 ? $q[1] . rawurlencode( self::staff( $sid ) ) : $q[0];
			}, $v );
		}
		// a path or query ("/x?doc=1234"): numbers the ledger knows are masked in it, as in a full link
		$linkish = (bool) preg_match( '#^/[^/\s]|^[^\s]*\?[^\s]*=#', $v );
		return $docs ? self::doc_pass( self::mask_quiet( $v ), $linkish ) : self::mask_quiet( $v );
	}

	/** JavaScript code: the text inside its string literals (those with a space in them: words people read). */
	public static function js_strings( $code ) {
		return (string) preg_replace_callback( '/\'((?:[^\'\\\\\n]|\\\\.)*+)\'|"((?:[^"\\\\\n]|\\\\.)*+)"|`((?:[^`\\\\$]|\\\\.)*+)`/u', function ( $m ) {
			$q   = $m[0][0];
			$in  = '' !== ( $m[3] ?? '' ) ? $m[3] : ( '' !== ( $m[2] ?? '' ) ? $m[2] : $m[1] );
			if ( strlen( $in ) > 2000 || ! preg_match( '/\s/u', $in ) || preg_match( '/^\s*[a-z\-]+\s*:\s*[^;]+;/i', $in ) ) {   // no spaces, or a run of CSS
				return $m[0];
			}
			$new = self::mask_prose( $in );
			// the literal's own escapes (\' \n) are kept as they are; a pass only ever swaps words for pseudonyms, so
			// if the quote, a backslash or a line break count changed, the whole string becomes [name] rather than broken code
			if ( $new === $in || substr_count( $new, $q ) !== substr_count( $in, $q ) || substr_count( $new, '\\' ) !== substr_count( $in, '\\' ) || false !== strpos( $new, "\n" ) ) {
				return $new === $in ? $m[0] : $q . self::NAME_GONE . $q;
			}
			return $q . $new . $q;
		}, (string) $code );
	}

	/** A key as snake_case lower case ("customerName" -> customer_name, "invoice-no" -> invoice_no). */
	public static function norm_key( $key ) {
		$k = (string) preg_replace( '/(?<=[a-z0-9])([A-Z])/', '_$1', (string) $key );
		return strtolower( (string) preg_replace( '/[\s\-]+/', '_', $k ) );
	}

	private static function key_kind( $key ) {
		$k = self::norm_key( $key );
		if ( '' === $k ) {
			return '';
		}
		$last  = preg_replace( '/^.*_(?=[a-z0-9]+$)/', '', $k );
		$tail2 = preg_match( '/([a-z0-9]+_[a-z0-9]+)$/', $k, $mm ) ? $mm[1] : $k;
		foreach ( array( $k, $tail2 ) as $c ) {
			if ( in_array( $c, self::SECRET_KEYS, true ) ) {
				return 'secret';
			}
			if ( isset( self::DOC_KEYS[ $c ] ) ) {
				return 'doc:' . self::DOC_KEYS[ $c ];
			}
			if ( in_array( $c, self::NAME_KEYS, true ) ) {
				return 'name';
			}
			if ( in_array( $c, self::EMAIL_KEYS, true ) ) {
				return 'email';
			}
			if ( in_array( $c, self::PHONE_KEYS, true ) ) {
				return 'phone';
			}
			if ( in_array( $c, self::STREET_KEYS, true ) ) {
				return 'street';
			}
			if ( in_array( $c, self::LOGIN_KEYS, true ) ) {
				return 'login';
			}
			if ( in_array( $c, self::INITIALS_KEYS, true ) ) {
				return 'initials';
			}
		}
		if ( in_array( $last, array( 'phone', 'email', 'street' ), true ) ) {
			return $last;
		}
		if ( in_array( $k, array( 'invoice', 'estimate', 'invoices', 'estimates' ), true ) || preg_match( '/(?:^|_)(invoice|estimate)_?(?:number|no|num|nbr)s?$/', $k, $dm ) ) {
			return 'doc:' . ( isset( $dm[1] ) ? $dm[1] : rtrim( $k, 's' ) );
		}
		if ( in_array( $k, self::MACHINE_KEYS, true ) || preg_match( '/_(?:ids?|uid|url|uri|href|src|nonce|at|hash|token|uuid|guid|type|status|slug|class|color|icon|version|ver|mode|tier|route|path|ts)$/', $k ) ) {
			return preg_match( '/(?:^|_)(?:ids?|uid)$/', $k ) ? 'id' : 'machine';
		}
		return 'prose';   // every other string: the full pass
	}

	/** A value by its key. Objects stay objects (stdClass), arrays stay arrays. */
	public static function mask_value( $v, $key = '' ) {
		if ( is_object( $v ) ) {
			$out = new stdClass();
			foreach ( get_object_vars( $v ) as $k => $x ) {
				$nk = self::mask_key( (string) $k );
				$out->{$nk} = self::mask_value( $x, (string) $k );
			}
			return $out;
		}
		if ( is_array( $v ) ) {
			$out = array();
			foreach ( $v as $k => $x ) {
				$out[ is_string( $k ) ? self::mask_key( $k ) : $k ] = self::mask_value( $x, is_string( $k ) ? $k : $key );
			}
			return $out;
		}
		if ( null === $v || is_bool( $v ) ) {
			return $v;
		}
		$kind = self::key_kind( $key );
		$s    = (string) $v;
		if ( 'secret' === $kind && '' !== trim( $s ) ) {
			return '[gate code sealed]';   // whatever it looks like: "#1234" is a code here, not a colour
		}
		if ( 'name' === $kind && self::own_field( $s ) ) {
			return $v;   // the account's own first or last name, as a whole field ("userFirstName")
		}
		if ( '' === trim( $s ) || self::is_pseudonym( $s ) || preg_match( '/^[\x{E010}-\x{E8FF}\s.,\-]+$/u', $s )
			|| preg_match( '/^#[0-9A-Fa-f]{3,8}$|^(?=[0-9]*[A-Fa-f])[0-9A-Fa-f]{6}$|^(?:Africa|America|Antarctica|Asia|Atlantic|Australia|Europe|Indian|Pacific|Etc)\/[A-Za-z_\/\-+0-9]+$|^UTC$/', trim( $s ) ) ) {
			return $v;   // empty, already a pseudonym, or only the business's / the account's own name
		}
		// a whole value that is a team member's login or display name, in any case, is their
		// pseudonym whatever the key ("name": "jdoe" -- WordPress's display name is the login by default)
		if ( is_string( $v ) && 'id' !== $kind && 'machine' !== $kind ) {
			$sid = self::staff_exact( $s, 'name' === $kind || 'login' === $kind || 1 === preg_match( '/(?:^|_)(?:name|login|author|user|username|owner|sender|recipient)$/', self::norm_key( $key ) ) );
			if ( $sid > 0 ) {
				return self::staff( $sid );
			}
		}
		switch ( $kind ) {
			case 'secret':
				return '[gate code sealed]';
			case 'name':
				foreach ( self::staff_names()['full'] as $n => $id ) {
					if ( 0 === strcasecmp( trim( $s ), $n ) ) {
						return self::staff( $id );
					}
				}
				return is_string( $v ) && preg_match( '/\p{L}/u', $s ) ? self::customer( $s ) : $v;
			case 'email':
				return false !== strpos( $s, '@' ) ? self::email( $s ) : ( is_string( $v ) ? self::mask_prose( $s ) : $v );
			case 'phone':
				return strlen( preg_replace( '/\D+/', '', $s ) ) >= 7 ? self::phone( $s ) : $v;
			case 'street':
				return is_string( $v ) ? self::street( $s ) : $v;
			case 'doc:invoice':
			case 'doc:estimate':
				return preg_match( '/\d/', $s ) ? self::doc( substr( $kind, 4 ), preg_replace( '/[^\d]/', '', $s ) ) : $v;
			case 'login':
				// a team member's login is their pseudonym, like their name
				$lg = self::staff_names()['logins'] ?? array();
				if ( isset( $lg[ strtolower( trim( $s ) ) ] ) ) {
					return self::staff( $lg[ strtolower( trim( $s ) ) ] );
				}
				return is_string( $v ) ? self::mask_prose( $s ) : $v;
			case 'initials':
				$ini = self::staff_names()['initials'];
				$u   = strtoupper( trim( $s ) );
				if ( isset( $ini[ $u ] ) ) {
					return self::staff( $ini[ $u ] );
				}
				return is_string( $v ) ? self::mask_prose( $s ) : $v;
		}
		// a whole value that is an invoice or estimate number the ledger knows is a document pseudonym under a key that
		// names a document ("number": "1234", "job_invoice": 1234, "reference") -- text or number; under any
		// other key a lone number stays a number (a cap, a limit, a count)
		if ( 'id' !== $kind && 'machine' !== $kind && ( is_string( $v ) || is_int( $v ) ) ) {
			$n = ltrim( trim( (string) $v ), '#0' );
			if ( preg_match( '/^\d{3,8}$/', $n ) && isset( self::known_docs()[ $n ] ) && ! ( 4 === strlen( $n ) && (int) $n >= 1990 && (int) $n <= 2099 )
				&& preg_match( '/(?:^|_)(?:number|no|num|invoice|estimate|po|doc|ref|reference)$/', self::norm_key( $key ) ) ) {
				return self::doc( self::known_docs()[ $n ], $n );
			}
		}
		if ( ! is_string( $v ) ) {
			return $v;   // a number in any other field: an amount, a count, an id
		}
		if ( 'id' === $kind ) {
			return self::mask_machine( $v, false );   // an id is never read as an invoice or estimate number (a chat session id can equal one)
		}
		// a machine value (a URL, a state) or a token-like string (no spaces, 16+ characters, digits in it: a key, a
		// nonce, a hash): known names, team, e-mails and invoice numbers only, so it is not broken
		if ( 'machine' === $kind || ( strlen( $v ) >= 16 && ! preg_match( '/\s/', $v ) && preg_match_all( '/\d/', $v ) >= 2 && preg_match( '/^[A-Za-z0-9+\/=_\-.:?&%#~]+$/', $v ) ) ) {
			return self::mask_machine( $v );
		}
		if ( preg_match( '#^(?:[a-z][a-z0-9+.\-]*:)?//\S+$|^/[^/\s]\S*$#i', trim( $v ) ) ) {
			return self::mask_machine( $v );   // a link in any field: read as a link
		}
		return self::mask_prose( $v );
	}

	/** A key that is a name, a phone or a document number (a map by customer) is masked; an identifier key is left alone. */
	private static function mask_key( $k ) {
		if ( preg_match( '/^\d+$/', $k ) ) {
			$n = ltrim( $k, '0' );
			if ( strlen( $k ) >= 10 ) {
				return self::phone( $k );
			}
			return isset( self::known_docs()[ $n ] ) && ! ( 4 === strlen( $k ) && (int) $k >= 1990 && (int) $k <= 2099 ) ? self::doc( self::known_docs()[ $n ], $n ) : $k;
		}
		return preg_match( '/^[a-zA-Z0-9_\-]*$/', $k ) && ! preg_match( '/@/', $k ) && ( strtolower( $k ) === $k || preg_match( '/^[a-z]+(?:[A-Z][a-z0-9]*)+$/', $k ) ) ? $k : self::mask_prose( $k );
	}

	/** JSON text in, masked JSON text out, same shape. Not JSON: null (the caller refuses or treats it as text). */
	public static function mask_json_text( $json ) {
		if ( 0 === self::$depth ) {
			return self::guarded( __FUNCTION__, func_get_args() );   // the business's own names, addresses and the account's own name are set aside first
		}
		$trim = trim( (string) $json );
		if ( '' === $trim ) {
			return (string) $json;
		}
		$d = json_decode( $trim );
		if ( null === $d && 'null' !== $trim ) {
			return null;
		}
		$out = json_encode( self::mask_value( $d ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $out ? null : $out;
	}

	/* ================================================================== 4. the second net: every response */

	/**
	 * HTML: text between tags gets every pass, with the name rule read ACROSS inline tags ("<b>Alex</b> <i>Quinn</i>");
	 * attributes people read get every pass; links and data attributes the quiet pass; scripts: every JSON literal in
	 * them (wp_localize_script's data, a JSON island) is masked BY FIELD like a JSON reply, the code around it quietly.
	 */
	public static function mask_html( $html, $banner = true ) {
		if ( 0 === self::$depth ) {
			return self::guarded( __FUNCTION__, func_get_args() );   // the business's own names, addresses and the account's own name are set aside first
		}
		$parts = preg_split( '/(<script\b[^>]*>.*?<\/script\s*>|<style\b[^>]*>.*?<\/style\s*>|<!--.*?-->|<(?:[^>"\']|"[^"]*"|\'[^\']*\')+>)/is', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			throw new RuntimeException( 'HTML could not be split' );   // refused by the caller
		}
		$kinds = array();
		$texts = array();
		foreach ( $parts as $i => $p ) {
			if ( 1 === $i % 2 ) {
				$kinds[ $i ] = preg_match( '/^<\/?(?:b|strong|i|em|u|span|a|mark|small|font|sup|sub|abbr|s|del|ins|q|cite|wbr|bdi|bdo|time|data)\b/i', $p ) ? 'inline' : 'block';
				$texts[ $i ] = '';
				if ( preg_match( '/^<script\b([^>]*)>(.*)<\/script\s*>$/is', $p, $sm ) ) {
					if ( preg_match( '/\btype\s*=\s*["\']?[^"\'\s>]*(?:json|importmap|speculationrules)/i', $sm[1] ) ) {
						$j = self::mask_json_text( $sm[2] );   // a JSON island (script modules, Interactivity, ld+json): by field
						$parts[ $i ] = '<script' . $sm[1] . '>' . ( null === $j ? '' : str_replace( '</', '<\/', $j ) ) . '</script>';
					} elseif ( preg_match( '/\btype\s*=\s*["\']?(?!text\/javascript|module|application\/javascript)[a-z]/i', $sm[1] ) ) {
						$parts[ $i ] = '<script' . $sm[1] . '>' . self::mask_html( $sm[2], false ) . '</script>';   // a template
					} else {
						$parts[ $i ] = '<script' . $sm[1] . '>' . self::mask_script( $sm[2] ) . '</script>';
					}
				} elseif ( preg_match( '/^<style/i', $p ) ) {
					continue;
				} elseif ( 0 === strpos( $p, '<!--' ) ) {
					$parts[ $i ] = '<!-- -->';   // comments are dropped (they can hold anything)
				} else {
					$asset = (bool) preg_match( '/^<(?:link|script)\b/i', $p );   // a stylesheet or script address holds no customer data
					$parts[ $i ] = (string) preg_replace_callback( '/(\s)([\w:\-]+)(\s*=\s*)("([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/', function ( $m ) use ( $asset ) {
						$name = strtolower( $m[2] );
						if ( $asset && in_array( $name, array( 'href', 'src', 'integrity', 'as', 'media', 'sizes', 'imagesrcset' ), true ) ) {
							return $m[0];
						}
						$q    = '"' === $m[4][0] ? '"' : ( "'" === $m[4][0] ? "'" : '"' );   // an unquoted value comes back quoted
						$raw  = '"' === $m[4][0] ? $m[5] : ( "'" === $m[4][0] ? ( $m[6] ?? '' ) : ( $m[7] ?? '' ) );
						if ( in_array( $name, array( 'class', 'id', 'style', 'type', 'rel', 'target', 'role', 'width', 'height', 'method', 'for', 'tabindex', 'nonce', 'integrity', 'crossorigin', 'loading', 'decoding', 'media', 'charset', 'lang', 'dir', 'data-lucide', 'data-icon', 'data-feather', 'autocomplete', 'inputmode', 'enterkeyhint', 'autocapitalize', 'autocorrect', 'spellcheck',
							'viewbox', 'transform', 'd', 'points', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'dx', 'dy', 'font-weight', 'font-size',
							'font-family', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'opacity', 'fill-opacity', 'offset', 'stop-color',
							'preserveaspectratio', 'xmlns', 'xmlns:xlink', 'version', 'allow', 'sandbox', 'referrerpolicy', 'colspan', 'rowspan', 'min', 'max',
							'step', 'maxlength', 'minlength', 'size', 'cols', 'rows', 'datetime', 'aria-hidden', 'aria-live', 'aria-expanded', 'aria-controls',
							'aria-haspopup', 'focusable', 'draggable', 'hidden', 'translate', 'lengthadjust', 'textlength', 'text-anchor',
							'dominant-baseline', 'letter-spacing', 'gradientunits', 'gradienttransform', 'patternunits', 'clip-path', 'mask', 'filter' ), true ) ) {
							return $m[0];
						}
						$dec = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						if ( 'href' === $name && preg_match( '/^tel:/i', $dec ) ) {
							$new = 'tel:' . self::phone( substr( $dec, 4 ) );
						} elseif ( 'href' === $name && preg_match( '/^mailto:/i', $dec ) ) {
							$new = 'mailto:' . self::email( substr( $dec, 7 ) );
						} elseif ( in_array( $name, array( 'title', 'alt', 'placeholder', 'aria-label', 'value', 'content', 'label', 'data-name', 'data-customer', 'data-title', 'data-label', 'data-tooltip', 'data-original-title' ), true )
							&& ! preg_match( '#^(?:[a-z][a-z0-9+.\-]*:)?//\S+$|^/[^/\s]\S*$#i', trim( $dec ) ) ) {
							$new = self::mask_prose( $dec );
						} elseif ( 0 === strpos( $name, 'data-' ) && preg_match( '/^\s*[\[{]/', $dec ) && null !== ( $j = self::mask_json_text( $dec ) ) ) {
							$new = $j;   // a JSON data attribute: by field
						} elseif ( 'srcdoc' === $name ) {
							$new = self::mask_html( $dec, false );   // a whole page in an attribute
						} elseif ( 0 === strpos( $name, 'on' ) ) {
							$new = self::js_strings( self::mask_quiet( $dec ) );   // a handler: its strings are text
						} else {
							$new = self::mask_machine( $dec );   // href, src, data-*: decoded, words inside, invoice numbers
						}
						return $new === $dec ? $m[0] : $m[1] . $m[2] . $m[3] . $q . htmlspecialchars( $new, ENT_QUOTES, 'UTF-8' ) . $q;
					}, $p );
				}
				continue;
			}
			$kinds[ $i ] = 'text';
			$texts[ $i ] = '' === $p ? '' : self::prose_base( html_entity_decode( $p, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		$texts = self::names_batched( $texts, $kinds );
		foreach ( $parts as $i => $p ) {
			if ( 'text' !== $kinds[ $i ] || '' === $p ) {
				continue;
			}
			$dec = html_entity_decode( $p, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $texts[ $i ] !== $dec ) {
				$parts[ $i ] = htmlspecialchars( (string) $texts[ $i ], ENT_NOQUOTES, 'UTF-8' );
			}
		}
		$out = implode( '', $parts );
		if ( ! $banner ) {
			return $out;
		}
		$bar = '<div id="' . self::BANNER_ID . '" role="status" style="position:fixed;left:0;right:0;bottom:0;z-index:2147483647;'
			. 'background:var(--sys-danger,currentColor);color:var(--sys-on-danger,inherit);font:600 13px/1.6 system-ui,sans-serif;'
			. 'text-align:center;padding:4px 8px;pointer-events:none">' . esc_html( self::banner_text() ) . '</div>'
			// controls the account may not use are not drawn for it (the server refuses each of them anyway)
			. '<style id="' . self::BANNER_ID . '-css">' . implode( ',', self::hidden_controls() ) . '{display:none!important}</style>';
		return preg_match( '/<body\b[^>]*>/i', $out ) ? (string) preg_replace( '/(<body\b[^>]*>)/i', '$1' . $bar, $out, 1 ) : $out;
	}

	/**
	 * The selectors of the controls hidden on the account's pages: HIDDEN_CONTROLS, and what an app adds on
	 * zdz_privacy_view_hidden_controls for buttons of its own that the account may not use. Only plain class and id
	 * selectors are taken (at most four in a row); Core's own cannot be taken away.
	 */
	public static function hidden_controls() {
		$f   = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_privacy_view_hidden_controls', self::HIDDEN_CONTROLS ) : self::HIDDEN_CONTROLS;
		$out = array();
		foreach ( array_merge( self::HIDDEN_CONTROLS, is_array( $f ) ? $f : array() ) as $s ) {
			$s = is_string( $s ) ? trim( (string) preg_replace( '/\s+/', ' ', $s ) ) : '';
			if ( preg_match( '/\A[.#][A-Za-z_][A-Za-z0-9_\-]{0,79}(?: [.#][A-Za-z_][A-Za-z0-9_\-]{0,79}){0,3}\z/', $s ) && count( $out ) < 60 ) {
				$out[ $s ] = true;
			}
		}
		return array_map( 'strval', array_keys( $out ) );
	}

	/** The line at the foot of every page the account sees. */
	public static function banner_text() {
		return function_exists( '__' ) ? __( 'Privacy View: customer details are hidden', 'zorderz' ) : 'Privacy View: customer details are hidden';
	}

	/** Where the JSON value starting at $at ends (strings and escapes respected), or -1. */
	private static function json_end( $s, $at ) {
		$depth = 0;
		$in    = false;
		$esc   = false;
		$n     = strlen( $s );
		for ( $i = $at; $i < $n; $i++ ) {
			$ch = $s[ $i ];
			if ( $in ) {
				if ( $esc ) {
					$esc = false;
				} elseif ( '\\' === $ch ) {
					$esc = true;
				} elseif ( '"' === $ch ) {
					$in = false;
				}
				continue;
			}
			if ( '"' === $ch ) {
				$in = true;
			} elseif ( '{' === $ch || '[' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch || ']' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					return $i;
				}
			} elseif ( "\n" === $ch && 0 === $depth ) {
				return -1;
			}
		}
		return -1;
	}

	/** A script: its JSON literals by field, the rest quietly (with \u00e9-style letters read as letters). */
	public static function mask_script( $code ) {
		if ( 0 === self::$depth ) {
			return self::guarded( __FUNCTION__, func_get_args() );   // the business's own names, addresses and the account's own name are set aside first
		}
		$code = (string) $code;
		if ( '' === trim( $code ) ) {
			return $code;
		}
		$out  = '';
		$pos  = 0;
		$todo = preg_match_all( '/(?:=|\(|:|,|return)\s*([\[{])/', $code, $mm, PREG_OFFSET_CAPTURE ) ? $mm[1] : array();
		foreach ( $todo as $hit ) {
			$at = (int) $hit[1];
			if ( $at < $pos ) {
				continue;   // inside a literal already masked
			}
			$end = self::json_end( $code, $at );
			if ( $end < 0 ) {
				continue;
			}
			$lit = substr( $code, $at, $end - $at + 1 );
			$d   = json_decode( $lit );
			if ( null === $d || ( ! is_object( $d ) && ! is_array( $d ) ) ) {
				continue;
			}
			$enc = json_encode( self::mask_value( $d ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
			if ( false === $enc ) {
				throw new RuntimeException( 'script data could not be encoded' );
			}
			$out .= self::quiet_code( substr( $code, $pos, $at - $pos ) ) . $enc;
			$pos  = $end + 1;
		}
		return $out . self::quiet_code( substr( $code, $pos ) );
	}

	private static function quiet_code( $code ) {
		if ( '' === trim( (string) $code ) ) {
			return (string) $code;
		}
		$code = (string) preg_replace_callback( '/\\\\u([0-9a-fA-F]{4})/', function ( $m ) {
			$ch = mb_chr( hexdec( $m[1] ), 'UTF-8' );
			return false !== $ch && preg_match( '/^\p{L}$/u', $ch ) ? $ch : $m[0];
		}, (string) $code );
		// code is not prose: the coder's customer index reads identifiers and keywords as names ('POST', .register(),
		// a comment), so code gets e-mails and team names only; its JSON data was masked by field above, and its
		// string literals get the full pass (js_strings)
		$code = (string) preg_replace_callback( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', function ( $m ) {
			return self::email( $m[0] );
		}, $code );
		return self::js_strings( self::staff_words( self::staff_full( $code ) ) );
	}

	private static function content_type() {
		foreach ( function_exists( 'headers_list' ) ? headers_list() : array() as $h ) {
			if ( 0 === stripos( $h, 'content-type:' ) ) {
				return strtolower( trim( substr( $h, 13 ) ) );
			}
		}
		return '';
	}

	/**
	 * The output callback. A streamed chunk is held until the response ends (a name split across two chunks is masked
	 * whole). Too large: refused before masking. Anything it cannot read: refused. Any error: refused.
	 */
	public static function filter_output( $buffer, $phase = null ) {
		$phase = null === $phase ? ( PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL ) : (int) $phase;
		$final = (bool) ( $phase & PHP_OUTPUT_HANDLER_FINAL );
		$clean = (bool) ( $phase & PHP_OUTPUT_HANDLER_CLEAN );
		if ( $clean ) {
			self::$held = '';      // ob_end_clean / ob_clean: what was held is discarded, as the caller asked
			return '';
		}
		self::$held .= (string) $buffer;
		if ( ! $final ) {
			if ( strlen( self::$held ) > self::MAX_BYTES ) {
				self::$held = '';
				return self::refusal( self::content_type() );
			}
			return '';             // held until the end
		}
		$all        = self::$held;
		self::$held = '';
		if ( self::$in_filter || '' === $all ) {
			return $all;
		}
		if ( ! self::room_for( strlen( $all ) ) ) {
			error_log( 'Zorderz Privacy View: not enough memory to mask a response of ' . strlen( $all ) . ' bytes, so it was refused.' );
			return self::refusal( self::content_type() );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 );   // phpcs:ignore -- a timeout inside the filter is a fatal error: give it time
		}
		self::$in_filter = true;
		try {
			$out = self::mask_response( $all, self::content_type() );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Privacy View: a response could not be masked, so it was refused (' . get_class( $e ) . ').' );
			$out = self::refusal( self::content_type() );
		}
		self::$in_filter = false;
		return $out;
	}

	/**
	 * Is there memory to mask this many bytes? A fatal error inside an output handler cannot be caught, and PHP then
	 * sends the handler's input as it is: so the filter checks first (raising the limit if it may) and refuses if not.
	 */
	public static function room_for( $bytes ) {
		$need  = (int) $bytes * self::MEM_PER_BYTE + 48 * 1048576;   // + the name rule's window and the word lists
		$used  = memory_get_usage( true );
		if ( $used + $need > 536870912 ) {
			return false;   // more than 512 MB: refused, whatever the limit says
		}
		$limit = self::bytes_of( (string) ini_get( 'memory_limit' ) );
		if ( $limit < 0 ) {
			return true;
		}
		if ( $used + $need <= $limit ) {
			return true;
		}
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'memory_limit', (string) ( $used + $need ) );   // phpcs:ignore
			$limit = self::bytes_of( (string) ini_get( 'memory_limit' ) );
		}
		return $limit < 0 || $used + $need <= $limit;
	}

	private static function bytes_of( $v ) {
		$v = trim( $v );
		if ( '' === $v || '-1' === $v ) {
			return -1;
		}
		$n = (int) $v;
		switch ( strtolower( substr( $v, -1 ) ) ) {
			case 'g':
				return $n * 1073741824;
			case 'm':
				return $n * 1048576;
			case 'k':
				return $n * 1024;
		}
		return $n;
	}

	public static function mask_response( $buffer, $content_type = '' ) {
		if ( 0 === self::$depth ) {
			return self::guarded( __FUNCTION__, func_get_args() );   // the business's own names, addresses and the account's own name are set aside first
		}
		$ct = strtolower( (string) $content_type );
		if ( strlen( (string) $buffer ) > self::MAX_BYTES ) {
			return self::refusal( $ct );
		}
		if ( '' !== $ct && ! preg_match( '#^(text/(html|plain|xml|css|csv|event-stream)|application/(json|xml)|application/[\w.+-]*\+json)#', $ct ) ) {
			return self::refusal( $ct );   // a PDF, a photo, a download, JavaScript built by PHP: refused
		}
		if ( 0 === strpos( $ct, 'text/css' ) ) {
			return self::no_uploads( $buffer );
		}
		$trim = ltrim( (string) $buffer );
		if ( '' !== $trim && ( false !== strpos( $ct, 'json' ) || '{' === $trim[0] || '[' === $trim[0] ) ) {
			$j = self::mask_json_text( $trim );
			if ( null !== $j ) {
				return self::no_uploads( $j );
			}
			if ( false !== strpos( $ct, 'json' ) ) {
				return self::refusal( $ct );   // says JSON, isn't: refused rather than guessed at
			}
		}
		if ( false !== strpos( $ct, 'html' ) || preg_match( '/<(?:!doctype|html|body|div|p|span|table)\b/i', substr( $trim, 0, 2000 ) ) ) {
			return self::no_uploads( self::mask_html( $buffer ) );
		}
		return self::no_uploads( self::mask_prose( $buffer ) );
	}

	/** What an upload address becomes -- a grey 4:3 picture (no quotes, no spaces: safe in any attribute,
	 *  JSON string or CSS url()). */
	const UPLOAD_GONE = 'data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22%20width=%2240%22%20height=%2230%22%3E%3Crect%20width=%2240%22%20height=%2230%22%20fill=%22%239ca3af%22/%3E%3C/svg%3E';

	/**
	 * The account is never handed an upload address. The web server sends those files itself, with no PHP in the way,
	 * so a photo reached that way would bypass the photo server's placeholder (a reply built for an administrator can
	 * carry them). Every address under the uploads folder -- absolute, root-relative or JSON-escaped, in HTML, JSON,
	 * scripts or CSS -- becomes the stand-in picture, except the business's own pictures (its logos and site icon).
	 * Photos reach the account only through ?zdz_media= (ZDZ_User_Media::serve), which decides placeholder or not.
	 */
	public static function no_uploads( $s ) {
		$s = (string) $s;
		$base = '/wp-content/uploads';
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$p = (string) parse_url( (string) ( wp_get_upload_dir()['baseurl'] ?? '' ), PHP_URL_PATH );
			if ( '' !== trim( $p, '/' ) ) {
				$base = '/' . trim( $p, '/' );
			}
		}
		$segs = array_values( array_filter( explode( '/', $base ), 'strlen' ) );
		if ( ! $segs || false === stripos( $s, end( $segs ) ) ) {
			return $s;
		}
		$sl  = '(?:\\\\?/)';   // a slash, or a JSON-escaped one
		$pat = '#(?:(?:https?:)?' . $sl . $sl . '[A-Za-z0-9.\-]+(?::\d+)?)?' . $sl . implode( $sl, array_map( function ( $x ) { return preg_quote( $x, '#' ); }, $segs ) )
			. $sl . '(?:[^\s"\'<>()\\\\&]|&(?!quot;|\#0*34;|\#x22;|apos;|\#0*39;)|\\\\/)+#i';   // stops at an escaped quote (JSON in an attribute), keeps & and &amp;
		$keep = self::company_uploads( $base );
		return (string) preg_replace_callback( $pat, function ( $m ) use ( $keep, $base ) {
			$plain = str_replace( '\\/', '/', $m[0] );
			$path  = (string) parse_url( ( 0 === strpos( $plain, '//' ) ? 'https:' : '' ) . $plain, PHP_URL_PATH );
			return isset( $keep[ strtolower( $path ) ] ) ? $m[0] : self::gone_url();
		}, $s );
	}

	/**
	 * The stand-in as a root-relative address of the theme's own grey picture (assets/images/pv-photo-hidden.svg): a page
	 * that puts the site's address in front of a path still gets a picture, where a data: address would become a broken
	 * "https://example.comdata:...". UPLOAD_GONE if the theme's address cannot be read.
	 */
	public static function gone_url() {
		$p = function_exists( 'get_template_directory_uri' ) ? (string) parse_url( (string) get_template_directory_uri(), PHP_URL_PATH ) : '';
		return '' !== $p ? rtrim( $p, '/' ) . '/assets/images/pv-photo-hidden.svg' : self::UPLOAD_GONE;
	}

	/** The business's own pictures under the uploads folder (logos, site icon), as lower-case paths. */
	private static function company_uploads( $base ) {
		$urls = array();
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$logo = (array) ZDZ_Business_Profile::get( 'brand.logo', array() );
			foreach ( array( 'wide', 'square' ) as $shape ) {
				foreach ( array( 'dark', 'light' ) as $ink ) {
					$urls[] = is_scalar( $logo[ $shape ][ $ink ] ?? null ) ? (string) $logo[ $shape ][ $ink ] : '';
				}
			}
			$urls[] = is_scalar( $logo['favicon'] ?? null ) ? (string) $logo['favicon'] : '';
		}
		if ( function_exists( 'get_theme_mod' ) ) {
			foreach ( array( 'zdz_logo_dark', 'zdz_logo_light', 'zdz_logo_vertical' ) as $m ) {
				$urls[] = (string) get_theme_mod( $m, '' );
			}
			$cl = (int) get_theme_mod( 'custom_logo', 0 );
			if ( $cl > 0 && function_exists( 'wp_get_attachment_url' ) ) {
				$urls[] = (string) wp_get_attachment_url( $cl );
			}
		}
		if ( function_exists( 'get_site_icon_url' ) ) {
			foreach ( array( 32, 180, 192, 270, 512 ) as $sz ) {
				$urls[] = (string) get_site_icon_url( $sz );
			}
		}
		$out = array();
		foreach ( $urls as $u ) {
			$p = (string) parse_url( $u, PHP_URL_PATH );
			if ( '' !== $p && false !== stripos( $p, $base ) ) {
				$out[ strtolower( $p ) ] = true;
			}
		}
		return $out;
	}

	public static function refusal( $ct = '' ) {
		$msg = function_exists( '__' ) ? __( 'Privacy View: this could not be shown with customer details hidden, so it was not sent.', 'zorderz' ) : 'Privacy View: this could not be shown with customer details hidden, so it was not sent.';
		if ( function_exists( 'headers_sent' ) && ! headers_sent() ) {
			header_remove( 'Content-Disposition' );
			header_remove( 'Content-Length' );
		}
		if ( false !== strpos( (string) $ct, 'json' ) ) {
			return (string) json_encode( array( 'success' => false, 'data' => array( 'message' => $msg ) ) );
		}
		if ( function_exists( 'headers_sent' ) && ! headers_sent() ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
		}
		return $msg;
	}

	/* ================================================================== photos (ZDZ_User_Media::serve) */

	/**
	 * A photo the account may see (an app answered zdz_privacy_view_real_photo), from its pixels alone: turned upright by
	 * its EXIF orientation, scaled to at most MAX_SIDE, drawn again as a JPEG -- no EXIF, no GPS, no camera, no name.
	 * '' if it cannot be done (no GD, too large to open, unreadable): the caller then sends the placeholder.
	 */
	const MAX_SIDE = 1600;
	public static function clean_image( $path, $mime ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! is_readable( (string) $path ) ) {
			return '';
		}
		$dim = @getimagesize( (string) $path );   // phpcs:ignore
		if ( ! $dim || $dim[0] < 1 || $dim[1] < 1 || $dim[0] * $dim[1] > 40000000 ) {
			return '';   // more than 40 megapixels: not opened
		}
		$open = array( 'image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp' );
		$fn   = $open[ (string) $mime ] ?? '';
		if ( '' === $fn || ! function_exists( $fn ) ) {
			return '';
		}
		$img = @$fn( (string) $path );   // phpcs:ignore
		if ( ! $img ) {
			return '';
		}
		if ( 'image/jpeg' === $mime && function_exists( 'exif_read_data' ) ) {
			$ex  = @exif_read_data( (string) $path );   // phpcs:ignore -- read for the turn only; nothing of it is sent
			$rot = array( 3 => 180, 6 => -90, 8 => 90 );
			$o   = (int) ( is_array( $ex ) ? ( $ex['Orientation'] ?? 1 ) : 1 );
			if ( isset( $rot[ $o ] ) ) {
				$r = imagerotate( $img, $rot[ $o ], 0 );
				if ( $r ) {
					imagedestroy( $img );
					$img = $r;
				}
			}
		}
		$w = imagesx( $img );
		$h = imagesy( $img );
		$k = min( 1, self::MAX_SIDE / max( $w, $h ) );
		$nw = max( 1, (int) round( $w * $k ) );
		$nh = max( 1, (int) round( $h * $k ) );
		$out = imagecreatetruecolor( $nw, $nh );
		imagefill( $out, 0, 0, imagecolorallocate( $out, 255, 255, 255 ) );   // a transparent PNG on white
		imagecopyresampled( $out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h );
		imagedestroy( $img );
		ob_start();
		imagejpeg( $out, null, 85 );
		$bytes = (string) ob_get_clean();
		imagedestroy( $out );
		return 0 === strpos( $bytes, "\xFF\xD8" ) ? $bytes : '';
	}

	/** A placeholder the size and shape of the photo: its date and a photo pseudonym, nothing else. */
	public static function photo_placeholder( $width, $height, $date = '', $media_id = 0 ) {
		$w = max( 40, min( 4000, (int) $width ?: 800 ) );
		$h = max( 40, min( 4000, (int) $height ?: 600 ) );
		$label = ( function_exists( '__' ) ? __( 'Photo hidden', 'zorderz' ) : 'Photo hidden' ) . ( preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $date ) ? ' · ' . substr( (string) $date, 0, 10 ) : '' )
			. ' · ' . self::tag( 'Photo' ) . self::code( 'photo', 'm' . (int) $media_id );
		$fs = max( 10, (int) round( min( $w, $h ) / 14 ) );
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">'
			. '<rect width="100%" height="100%" fill="#9ca3af"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" '
			. 'font-family="system-ui,sans-serif" font-size="' . $fs . '" fill="#111827">' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ) . '</text></svg>';
	}

	/**
	 * The photo server's answer for a Privacy View account (ZDZ_User_Media serves a photo through here, then stops): a
	 * photo an app vouches for (zdz_privacy_view_real_photo, e.g. a made-up test record's) is re-drawn from its pixels;
	 * anything else, or anything that cannot be re-drawn, is a placeholder the photo's size and shape. Never the file,
	 * its name or its location data.
	 */
	public static function serve_photo( $path, $mime, array $row ) {
		if ( in_array( (string) $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true )
			&& true === apply_filters( 'zdz_privacy_view_real_photo', false, $row ) ) {
			$clean = self::clean_image( $path, (string) $mime );
			if ( '' !== $clean ) {
				while ( ob_get_level() > 0 ) {
					ob_end_clean();
				}
				header( 'Content-Type: image/jpeg' );
				header( 'Content-Disposition: inline; filename="photo.jpg"' );
				header( 'X-Content-Type-Options: nosniff', true );
				header( 'Cache-Control: private, no-store', true );
				echo $clean;   // phpcs:ignore -- a JPEG drawn from pixels alone
				exit;
			}
		}
		$dim = ( 0 === strpos( (string) $mime, 'image/' ) && function_exists( 'getimagesize' ) ) ? @getimagesize( (string) $path ) : false;   // phpcs:ignore
		$svg = self::photo_placeholder( $dim ? $dim[0] : 800, $dim ? $dim[1] : 600, (string) ( $row['created_at'] ?? '' ), (int) ( $row['id'] ?? 0 ) );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="photo-hidden.svg"' );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'Cache-Control: private, no-store', true );
		echo $svg;   // phpcs:ignore -- built from numbers and the plugin's own label only
		exit;
	}

	/* ================================================================== the admin's look-up */

	public static function menu() {
		if ( self::is_on() ) {
			return;
		}
		add_submenu_page( 'zdz-core-settings', __( 'Privacy View', 'zorderz' ), __( 'Privacy View', 'zorderz' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * What a pseudonym stands for: customers (the coder's names: the sales ledger and the site's other names), team
	 * members, invoice and estimate numbers in the ledger. E-mails, phones, streets and names typed nowhere in the lists are not kept to
	 * look up: find them on the customer's record (the pseudonym beside them).
	 *
	 * @return array<int,string>
	 */
	public static function lookup( $pseudonym ) {
		$in = trim( (string) $pseudonym );
		// "Bcdf·5E8GZ" -- the reply's tag, then the code. The tag sets the key again; every kind is tried (a pseudonym
		// does not say what it is), and each hit says what it was.
		if ( preg_match( '/^([' . self::VIEW_ABC . '][' . strtolower( self::VIEW_ABC ) . ']{3})\s*[·.\-]?\s*([0-9A-Z_]{5})$/iu', $in, $m ) ) {
			$tag  = strtoupper( $m[1][0] ) . strtolower( substr( $m[1], 1 ) );
			$code = strtoupper( $m[2] );
			if ( ! self::well_formed( $code ) || self::labels() ) {
				return array();
			}
			$keep = self::$view;
			self::new_view( $tag );
			try {
				$hits = array_merge(
					array_map( function ( $h ) { return __( 'customer:', 'zorderz' ) . ' ' . $h; }, self::lookup_kind( 'cust', $code ) ),
					array_map( function ( $h ) { return __( 'team member:', 'zorderz' ) . ' ' . $h; }, self::lookup_kind( 'staff', $code ) ),
					array_map( function ( $h ) { return __( 'invoice', 'zorderz' ) . ' ' . $h; }, self::lookup_kind( 'inv', $code ) ),
					array_map( function ( $h ) { return __( 'estimate', 'zorderz' ) . ' ' . $h; }, self::lookup_kind( 'est', $code ) )
				);
			} finally {
				self::new_view( $keep );
			}
			return array_values( array_unique( $hits ) );
		}
		if ( ! preg_match( '/^(Cust|Staff|Inv|Est)\s*[·.\-]?\s*([0-9A-Z_]{5})$/iu', $in, $m ) ) {
			return array();
		}
		$code = strtoupper( $m[2] );
		if ( ! self::well_formed( $code ) ) {
			return array();
		}
		return self::lookup_kind( strtolower( $m[1] ), $code );
	}

	/** One kind's matches for a code under the current key. */
	private static function lookup_kind( $kind, $code ) {
		$hits = array();
		if ( 'cust' === $kind ) {
			$idx = self::coder()->index();
			foreach ( array_merge( array_keys( (array) ( $idx['ledger'] ?? array() ) ), array_keys( (array) ( $idx['other'] ?? array() ) ) ) as $k ) {
				if ( self::code( 'customer', $k ) === $code ) {
					$hits[] = $k;
				}
			}
		} elseif ( 'staff' === $kind ) {
			$ids = array_unique( array_merge( array_values( self::staff_names()['full'] ), array_values( self::staff_names()['words'] ), array_values( self::staff_names()['initials'] ) ) );
			foreach ( $ids as $id ) {
				if ( self::code( 'staff', 'u' . (int) $id ) === $code ) {
					$u = function_exists( 'get_userdata' ) ? get_userdata( (int) $id ) : null;
					$hits[] = ( $u && isset( $u->display_name ) ) ? (string) $u->display_name : ( 'user ' . (int) $id );
				}
			}
		} else {
			global $wpdb;
			$doc     = 'est' === $kind ? 'estimate' : 'invoice';
			$ledgers = self::doc_ledgers();
			if ( ! isset( $ledgers[ $doc ] ) || ! is_object( $wpdb ) ) {
				return array();
			}
			$t     = $wpdb->prefix . $ledgers[ $doc ][0];
			$col   = $ledgers[ $doc ][1];
			$quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			$nums  = (array) $wpdb->get_col( "SELECT DISTINCT {$col} FROM {$t} WHERE {$col} <> '' LIMIT 100000" );   // phpcs:ignore -- table and column checked in doc_ledgers()
			if ( null !== $quiet ) {
				$wpdb->suppress_errors( $quiet );
			}
			foreach ( $nums as $n ) {
				if ( self::code( $doc, ltrim( (string) $n, '#0' ) ) === $code ) {
					$hits[] = '#' . $n;
				}
			}
		}
		return array_values( array_unique( $hits ) );
	}

	/**
	 * Lets the account cite a thing by the code its own answer showed it. What a pseudonym stands for, ONLY when $text --
	 * the chat's own earlier answers, as saved -- already holds that thing:
	 *   array( 'kind' => 'customer' | 'team' | 'invoice' | 'estimate', 'value' => the name or the number )
	 * or null: not a pseudonym of this shape, nothing in $text it could be, or more than one thing (never a guess).
	 * The account learns nothing from it it was not already shown in that chat: the turn goes on with the real name
	 * (the Ai router codes it again on the way out) and the answer comes back to the account masked, under a new tag.
	 * A code from another chat or page stays a code. E-mails, phones and streets are not resolved.
	 */
	public static function resolve_in( $pseudonym, $text ) {
		$in = trim( (string) $pseudonym );
		if ( self::labels() || ! preg_match( '/^([' . self::VIEW_ABC . '][' . strtolower( self::VIEW_ABC ) . ']{3})\s*[·.\-]?\s*([0-9A-Z_]{5})$/iu', $in, $m ) ) {
			return null;
		}
		$tag  = strtoupper( $m[1][0] ) . strtolower( substr( $m[1], 1 ) );
		$code = strtoupper( $m[2] );
		if ( ! self::well_formed( $code ) ) {
			return null;
		}
		$plain = self::plain_of( (string) $text );
		if ( '' === trim( $plain ) ) {
			return null;
		}
		$keep = self::$view;
		self::new_view( $tag );
		$hits = array();
		try {
			// customers: the site's list of names, each one only if the text holds it as whole words
			$idx  = self::coder()->index();
			$norm = ' ' . self::norm( $plain ) . ' ';
			if ( ! isset( self::$by_code[ $tag ] ) ) {   // every name's code under this tag once, not once a code
				$map = array();
				foreach ( array( 'ledger', 'other' ) as $part ) {
					foreach ( array_keys( (array) ( $idx[ $part ] ?? array() ) ) as $k ) {
						$k = (string) $k;
						if ( '' !== $k ) {
							$map[ self::code( 'customer', $k ) ][] = $k;
						}
					}
				}
				self::$by_code = array( $tag => $map );   // one tag kept
			}
			foreach ( self::$by_code[ $tag ][ $code ] ?? array() as $k ) {
				if ( false !== strpos( $norm, ' ' . $k . ' ' ) ) {
					$hits[ 'c:' . $k ] = array( 'kind' => 'customer', 'value' => self::cased( $k, $plain, $idx ) );
				}
			}
			// team members: by any of the names the masking knows them by, as written in the text
			$st  = self::staff_names();
			$ids = array_unique( array_map( 'intval', array_merge( array_values( $st['full'] ), array_values( $st['words'] ) ) ) );
			foreach ( $ids as $id ) {
				if ( $id <= 0 || self::code( 'staff', 'u' . $id ) !== $code ) {
					continue;
				}
				$seen = false;
				foreach ( $st['full'] as $name => $sid ) {
					if ( (int) $sid === $id && preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( (string) $name, '/' ) . '(?![\p{L}\p{N}])/iu', $plain ) ) {
						$seen = true;
						break;
					}
				}
				foreach ( $seen ? array() : $st['words'] as $w => $sid ) {
					if ( (int) $sid === $id && preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( (string) $w, '/' ) . '(?![\p{L}\p{N}])/u', $plain ) ) {
						$seen = true;
						break;
					}
				}
				if ( $seen ) {
					$u = function_exists( 'get_userdata' ) ? get_userdata( $id ) : null;
					if ( $u && isset( $u->display_name ) && '' !== (string) $u->display_name ) {
						$hits[ 's:' . $id ] = array( 'kind' => 'team', 'value' => (string) $u->display_name );
					}
				}
			}
			// invoices and estimates: only numbers the text holds
			if ( preg_match_all( '/(?<![\d\p{L}])#?(\d{3,8})(?![\d])/u', $plain, $nm ) ) {
				foreach ( array_unique( $nm[1] ) as $n ) {
					$t = ltrim( (string) $n, '0' );
					if ( '' === $t ) {
						continue;
					}
					foreach ( array( 'invoice', 'estimate' ) as $kind ) {
						if ( self::code( $kind, $t ) === $code ) {
							$hits[ $kind . ':' . $t ] = array( 'kind' => $kind, 'value' => '#' . $n );
						}
					}
				}
			}
		} catch ( \Throwable $e ) {
			$hits = array();   // no coder, no list: no answer (never a guess)
		} finally {
			self::new_view( $keep );
		}
		return 1 === count( $hits ) ? array_values( $hits )[0] : null;
	}

	/** The text with the router's ⟦c⟧ / ⟦e⟧ codes opened (a code that will not open is left out). */
	private static function plain_of( $t ) {
		if ( false === strpos( $t, '⟦' ) ) {
			return $t;
		}
		try {
			$coder = self::coder();
		} catch ( \Throwable $e ) {
			return (string) preg_replace( '/⟦[ce]:[A-Za-z0-9_\-]*⟧/u', ' ', $t );
		}
		return (string) preg_replace_callback( '/⟦([ce]):([A-Za-z0-9_\-]*)⟧/u', function ( $m ) use ( $coder ) {
			$o = 'c' === $m[1] ? ( method_exists( $coder, 'ledger_name' ) ? $coder->ledger_name( (int) $m[2] ) : null ) : $coder->open( $m[2] );
			return null === $o ? ' ' : (string) $o;
		}, $t );
	}

	/** A customer's name as the text writes it (else the ledger's, else in title case). */
	private static function cased( $k, $plain, array $idx ) {
		$words = preg_split( '/\s+/', (string) $k, -1, PREG_SPLIT_NO_EMPTY );
		if ( $words && preg_match( '/(?<![\p{L}\p{N}])' . implode( '[^\p{L}\p{N}]+', array_map( function ( $w ) { return preg_quote( $w, '/' ); }, $words ) ) . '(?![\p{L}\p{N}])/iu', $plain, $m ) ) {
			return trim( $m[0] );
		}
		$cid = $idx['ledger'][ $k ] ?? null;
		if ( is_numeric( $cid ) && (int) $cid > 0 ) {
			$n = self::coder()->ledger_name( (int) $cid );
			if ( is_string( $n ) && '' !== trim( $n ) ) {
				return trim( $n );
			}
		}
		return ucwords( (string) $k );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) || self::is_on() ) {
			wp_die( esc_html__( 'Not available.', 'zorderz' ) );
		}
		$q    = isset( $_GET['pv'] ) ? trim( (string) wp_unslash( $_GET['pv'] ) ) : '';   // phpcs:ignore
		$hits = '' !== $q ? self::lookup( $q ) : null;
		echo '<div class="wrap"><h1>' . esc_html__( 'Privacy View', 'zorderz' ) . '</h1>';
		echo '<p>' . esc_html__( 'An account with Privacy View on (ticked on its user profile by an administrator) sees pseudonyms instead of customer details, team members\' names and invoice numbers, reaches only the chat and Ai usage, and cannot change anything. Paste a pseudonym from a report to see what it stands for.', 'zorderz' ) . '</p>';
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="text" name="pv" value="' . esc_attr( $q ) . '" placeholder="Bcdf·7K3QX" class="regular-text"> <button class="button">' . esc_html__( 'Look up', 'zorderz' ) . '</button></form>';
		if ( null !== $hits ) {
			if ( ! $hits ) {
				echo '<p>' . esc_html__( 'No match. Check the spelling (the last character is a check character). E-mails, phones and streets are not kept to look up: find them on the customer\'s record.', 'zorderz' ) . '</p>';
			} else {
				echo '<ul>';
				foreach ( $hits as $h ) {
					echo '<li>' . esc_html( $h ) . '</li>';
				}
				echo '</ul>';
			}
		}
		$users = function_exists( 'get_users' ) ? get_users( array( 'meta_key' => self::META, 'meta_value' => '1', 'fields' => array( 'ID', 'display_name' ) ) ) : array();
		echo '<h2>' . esc_html__( 'Accounts with Privacy View on', 'zorderz' ) . '</h2>';
		echo $users ? '<ul>' . implode( '', array_map( function ( $u ) { return '<li>' . esc_html( $u->display_name ) . '</li>'; }, $users ) ) . '</ul>' : '<p>' . esc_html__( 'None.', 'zorderz' ) . '</p>';   // phpcs:ignore -- escaped per item
		self::render_dm_partner();
		if ( class_exists( 'ZDZ_Privacy_View_Gate_File' ) ) {
			ZDZ_Privacy_View_Gate_File::render();   // the optional must-use file: where it stands, and the buttons
		}
		echo '</div>';
	}

	/* ================================================================== the one person it may message */

	const DM_OPTION = 'zdz_privacy_view_dm_to';

	/** May this user be the one a Privacy View account messages? An administrator, never a Privacy View account, never
	 *  the shared device. */
	public static function dm_partner_ok( $uid ) {
		$uid = (int) $uid;
		return $uid > 0 && function_exists( 'user_can' ) && user_can( $uid, 'manage_options' ) && ! self::is_on( $uid )
			&& ( ! class_exists( 'ZDZ_Hierarchy' ) || ! method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) || ! ZDZ_Hierarchy::is_kiosk( $uid ) )
			&& ( ! class_exists( 'ZDZ_User_Roles' ) || ! method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) || ! ZDZ_User_Roles::is_shared_device( $uid ) );
	}

	/**
	 * The one administrator a Privacy View account may send a direct message to and read the thread with -- chosen on
	 * this page by an administrator, never by the account, and checked again every time it is used (still an
	 * administrator, still not a Privacy View account). 0: nobody, so nothing in the messaging app is open to it.
	 */
	public static function dm_partner() {
		$uid = function_exists( 'get_option' ) ? (int) get_option( self::DM_OPTION, 0 ) : 0;
		return self::dm_partner_ok( $uid ) ? $uid : 0;
	}

	private static function render_dm_partner() {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['zdz_pv_dm_to'] ) && function_exists( 'check_admin_referer' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- only compared with the literal 'POST'
			check_admin_referer( 'zdz_pv_dm_to' );
			$to = (int) $_POST['zdz_pv_dm_to'];
			if ( 0 === $to || self::dm_partner_ok( $to ) ) {
				update_option( self::DM_OPTION, $to, false );
			}
		}
		$now    = self::dm_partner();
		$admins = function_exists( 'get_users' ) ? get_users( array( 'capability' => 'manage_options', 'fields' => array( 'ID', 'display_name' ) ) ) : array();
		echo '<h2>' . esc_html__( 'Who it may message', 'zorderz' ) . '</h2>';
		echo '<p>' . esc_html__( 'A Privacy View account may send a direct message to one administrator only, and read only that conversation (customer details hidden). Nobody: messaging stays closed to it.', 'zorderz' ) . '</p>';
		echo '<form method="post">';
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'zdz_pv_dm_to' );
		}
		echo '<select name="zdz_pv_dm_to"><option value="0">' . esc_html__( 'Nobody', 'zorderz' ) . '</option>';
		foreach ( (array) $admins as $u ) {
			if ( ! self::dm_partner_ok( (int) $u->ID ) ) {
				continue;
			}
			echo '<option value="' . (int) $u->ID . '"' . ( (int) $u->ID === $now ? ' selected' : '' ) . '>' . esc_html( $u->display_name ) . '</option>';
		}
		echo '</select> <button class="button button-primary">' . esc_html__( 'Save', 'zorderz' ) . '</button></form>';
	}
}
