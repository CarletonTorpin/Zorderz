<?php
/**
 * ZCC_Ai — Ai proposals for the invoice lines the catalog cannot place. A person confirms.
 *
 * Most lines are placed by the Item Engine's words. The few it cannot place are booked as
 * "unknown" ($0 cost, flagged) — never guessed. An administrator can ask the Ai which catalog
 * item each such line most likely is. The answer is a PROPOSAL only:
 *
 *   - nothing in the pay path reads a proposal; the ledger, the cost book and the classifier
 *     are untouched by asking;
 *   - an administrator confirms (or corrects) a proposal, which writes a confirmed mapping
 *     (ZCC_Line_Map) — from then on that exact line text is that item, deterministically;
 *   - or rejects it, which keeps the line unknown.
 *
 * The call goes only through the platform's Ai router, ZDZ_Core_LLM::ask( 'classify' ), as the
 * registered caller 'commission-calculator': the router codes every customer detail before
 * anything leaves the site (ZDZ_Private), picks the model the business configured, holds the
 * call to its money caps, and refuses the shared device. No model is named here. The catalog
 * list in the instructions is the business's own words (ids, names, aliases; never a cost),
 * marked as company text so its product words stay as written.
 *
 * Proposals are kept with the line's text shown with placeholders (never a customer's name),
 * keyed by the hash the confirmed mapping uses. At most one ask per ten minutes, site-wide.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Ai {

	const CALLER      = 'commission-calculator';
	const OPTION      = 'zcc_ai_proposals';
	const LOCK_OPTION = 'zcc_ai_lock';
	const RULES_OPT   = 'zcc_ai_rules';
	const MAX_LINES   = 40;
	const DEADLINE_S  = 60;
	const EVERY_S     = 600;
	const MAX_KEEP    = 300;

	/**
	 * Tests install a callable( string $role, array $request, array $ctx ) here in place of the
	 * router. Production leaves it null.
	 *
	 * @var callable|null
	 */
	public static $ask = null;

	/**
	 * Tests install the catalog here (a list of items). Production leaves it null and reads the
	 * Item Engine.
	 *
	 * @var array|null
	 */
	public static $catalog = null;

	/**
	 * The priced lines the classifier could not place, unique by line text, at most MAX_LINES:
	 * key => text (the text it was classified on).
	 */
	public static function unclear_lines( array $invoices ): array {
		$out = [];
		foreach ( $invoices as $inv ) {
			foreach ( (array) ( $inv['lines'] ?? [] ) as $line ) {
				if ( ! is_array( $line ) || abs( (float) ( $line['amount'] ?? 0 ) ) <= 0.005 ) {
					continue;
				}
				$cls = ZCC_Classifier::classify_line( $line );
				if ( 'unknown' !== ( $cls['category'] ?? '' ) ) {
					continue;
				}
				$text = class_exists( 'ZCC_Places' ) ? ZCC_Places::line_text( $line ) : (string) ( $line['description'] ?? '' );
				$key  = ZCC_Line_Map::key( $text );
				if ( '' !== $key && ! isset( $out[ $key ] ) ) {
					$out[ $key ] = $text;
					if ( count( $out ) >= self::MAX_LINES ) {
						return $out;
					}
				}
			}
		}
		return $out;
	}

	/** The rules every ask carries, then the business's own (option `zcc_ai_rules`, filter of the same name). */
	public static function rules(): array {
		$core = [
			'Answer with a catalog item id only when the line clearly is that item; otherwise answer "" (no item). A person checks every answer.',
			'A line that names no product or service (a discount, a tax, a fee, a tip, a payment, a note) is non_product, with no item.',
			'A charge for parts alone takes the part\'s own catalog item when there is one; otherwise no item, never the whole product\'s.',
			'A reused, repurposed or modified item is not a new one: answer a new item only when the line names a new one.',
			'A place name is never a product.',
		];
		$own = function_exists( 'get_option' ) ? get_option( self::RULES_OPT, [] ) : [];
		$own = (array) apply_filters( self::RULES_OPT, is_array( $own ) ? $own : [] );
		foreach ( $own as $r ) {
			$r = trim( is_scalar( $r ) ? (string) $r : '' );
			if ( '' !== $r ) {
				$core[] = mb_substr( $r, 0, 400 );
			}
		}
		return $core;
	}

	/** The catalog as the Ai reads it: id, name and aliases. Never a cost or a price. */
	public static function catalog_block(): string {
		if ( is_array( self::$catalog ) ) {
			$items = self::$catalog;
		} elseif ( class_exists( 'ZDZ_Item_Engine' ) && method_exists( 'ZDZ_Item_Engine', 'all' ) ) {
			$items = (array) ZDZ_Item_Engine::all();
		} else {
			return '';
		}
		$out = '';
		foreach ( $items as $item ) {
			$id = (string) ( $item['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$aliases = array_slice( array_map( 'strval', (array) ( $item['aliases'] ?? [] ) ), 0, 12 );
			$out    .= '- ' . $id . ' | ' . (string) ( $item['display_name'] ?? $item['name'] ?? $id ) . ( $aliases ? ' | also: ' . implode( ', ', $aliases ) : '' ) . "\n";
		}
		return $out;
	}

	public static function system_prompt(): string {
		$s  = "You sort invoice lines into a business's catalog. You only propose; a person decides.\n\nRules:\n";
		foreach ( self::rules() as $r ) {
			$s .= '- ' . $r . "\n";
		}
		$s .= "\nThe catalog (id | name | other words for it):\n" . self::catalog_block();
		return $s;
	}

	/** The answer's shape (no unions: "" means no item). */
	public static function schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'lines' ],
			'properties'           => [
				'lines' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => [ 'i', 'item_id', 'non_product', 'confidence', 'note' ],
						'properties'           => [
							'i'           => [ 'type' => 'integer' ],
							'item_id'     => [ 'type' => 'string' ],
							'non_product' => [ 'type' => 'boolean' ],
							'confidence'  => [ 'type' => 'number' ],
							'note'        => [ 'type' => 'string' ],
						],
					],
				],
			],
		];
	}

	/** Who may ask: the administrator tier who may see costs (never Privacy View, View As or the shared device). */
	public static function may_ask( int $uid ): bool {
		return ZCC_Visibility::may_see_costs( $uid );
	}

	/**
	 * Ask the Ai about these lines and keep its answers as proposals. Writes nothing else.
	 *
	 * @param array $lines key => text (from unclear_lines()).
	 * @return array{ ok:bool, proposals?:array, error?:string }
	 */
	public static function propose( array $lines, int $uid ): array {
		if ( ! self::may_ask( $uid ) ) {
			return [ 'ok' => false, 'error' => 'not_allowed' ];
		}
		$lines = array_slice( array_filter( $lines, 'is_string' ), 0, self::MAX_LINES, true );
		if ( ! $lines ) {
			return [ 'ok' => true, 'proposals' => [] ];
		}
		$last = (int) ( function_exists( 'get_option' ) ? get_option( self::LOCK_OPTION, 0 ) : 0 );
		if ( $last > 0 && time() - $last < self::EVERY_S ) {
			return [ 'ok' => false, 'error' => 'too_soon' ];
		}
		update_option( self::LOCK_OPTION, time(), false );

		$keys  = array_keys( $lines );
		$items = [];
		foreach ( array_values( $lines ) as $i => $text ) {
			$items[] = [ 'i' => $i, 'line' => $text ];
		}
		$request = [
			'system'         => self::system_prompt(),
			'system_company' => true,
			'messages'       => [ [ 'role' => 'user', 'content' => "Sort these invoice lines:\n\n" . wp_json_encode( $items ) ] ],
			'json_schema'    => self::schema(),
		];
		$ctx = [ 'caller' => self::CALLER, 'user_id' => $uid, 'deadline' => time() + self::DEADLINE_S ];

		if ( is_callable( self::$ask ) ) {
			$res = call_user_func( self::$ask, 'classify', $request, $ctx );
		} elseif ( class_exists( 'ZDZ_Core_LLM' ) ) {
			$res = ZDZ_Core_LLM::ask( 'classify', $request, $ctx );
		} else {
			return [ 'ok' => false, 'error' => 'no_router' ];
		}
		if ( is_wp_error( $res ) || ! is_array( $res ) || ! is_array( $res['json']['lines'] ?? null ) ) {
			error_log( 'ZCC Ai: no answer (' . ( is_wp_error( $res ) ? $res->get_error_code() : 'bad_answer' ) . ')' );
			return [ 'ok' => false, 'error' => 'no_answer' ];
		}

		$coder = ( class_exists( 'ZDZ_Private' ) && method_exists( 'ZDZ_Private', 'for' ) ) ? ZDZ_Private::for( 'llm' ) : null;
		$all   = self::all();
		$new   = [];
		foreach ( $res['json']['lines'] as $row ) {
			$i = (int) ( $row['i'] ?? -1 );
			if ( ! isset( $keys[ $i ] ) ) {
				continue;
			}
			$key     = $keys[ $i ];
			$item_id = sanitize_key( (string) ( $row['item_id'] ?? '' ) );
			if ( '' !== $item_id && ! is_array( zcc_item_get( $item_id ) ) ) {
				$item_id = ''; // an id the catalog does not have is no proposal
			}
			$note = (string) ( $row['note'] ?? '' );
			$note = $coder ? (string) $coder->decode( $note, false ) : '';
			$new[ $key ] = [
				'shown'       => self::shown( $lines[ $key ] ),
				'item_id'     => $item_id,
				'non_product' => ! empty( $row['non_product'] ),
				'confidence'  => max( 0.0, min( 1.0, (float) ( $row['confidence'] ?? 0 ) ) ),
				'note'        => mb_substr( $note, 0, 300 ),
				'model'       => sanitize_text_field( (string) ( $res['model'] ?? '' ) ),
				'asked_by'    => $uid,
				'at'          => time(),
				'status'      => 'proposed',
			];
		}
		$all = array_merge( $all, $new );
		if ( count( $all ) > self::MAX_KEEP ) {
			$all = array_slice( $all, -self::MAX_KEEP, null, true );
		}
		update_option( self::OPTION, $all, false );
		return [ 'ok' => true, 'proposals' => $new ];
	}

	/** A line as the page shows it: coded the way the router codes it, then placeholders. Never a name. */
	public static function shown( string $line ): string {
		if ( ! class_exists( 'ZDZ_Private' ) || ! method_exists( 'ZDZ_Private', 'for' ) ) {
			return '';
		}
		$c = ZDZ_Private::for( 'llm' );
		return mb_substr( (string) $c->decode( $c->encode( $line ), false ), 0, 300 );
	}

	public static function all(): array {
		$v = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
		return is_array( $v ) ? $v : [];
	}

	/**
	 * A person settles a proposal: confirm it (as proposed, or as another item) or reject it.
	 *
	 * @return array{ok:bool,error?:string}
	 */
	public static function settle( string $key, string $decision, string $item_id, int $uid ): array {
		if ( ! self::may_ask( $uid ) ) {
			return [ 'ok' => false, 'error' => 'not_allowed' ];
		}
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return [ 'ok' => false, 'error' => 'not_found' ];
		}
		if ( 'confirm' === $decision ) {
			$item_id = '' !== $item_id ? $item_id : (string) $all[ $key ]['item_id'];
			$r       = ZCC_Line_Map::confirm( $key, $item_id, $uid );
			if ( empty( $r['ok'] ) ) {
				return $r;
			}
			$all[ $key ]['status']  = 'confirmed';
			$all[ $key ]['item_id'] = sanitize_key( $item_id );
		} else {
			$all[ $key ]['status'] = 'rejected';
		}
		$all[ $key ]['settled_by'] = $uid;
		$all[ $key ]['settled_at'] = time();
		update_option( self::OPTION, $all, false );
		return [ 'ok' => true ];
	}
}
