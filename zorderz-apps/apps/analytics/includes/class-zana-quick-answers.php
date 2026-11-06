<?php
/**
 * ZANA_Quick_Answers — the read verb `ask.quick`: a question typed in the home screen's ask bar, answered right under it
 * from stored data when one of the ledger's rule routes takes it. No model, no live billing or CRM call, nothing
 * written; anything else answers `answered: false` and the ask bar offers the chat as before.
 *
 * The routes, in order (each decides who may, through ZANA_Ledger_Access):
 *   billing_gaps   accepted estimates never invoiced (a list, cut to its first lines; the chat has the rest)
 *   lead_sources   lead counts by source (ZANA_Lead_Sources)
 *   ledger         sales, estimates, money owed, invoices paid, customers (ZANA_Ledger_Answers; a list is the chat's)
 *   …and any route another part of the app adds on the `zana_quick_routes` filter: an array of
 *   callable( $question, $uid ) => array( kind, markdown ) | null, tried after the ledger's own (the chat's hours and
 *   app-read routes plug in here).
 *
 * The theme's orchestrator calls this through the verb registry (ZDZ_Capabilities::invoke), which names the signed-in
 * person and refuses the shared device. The answer's markdown is rendered here to a small, fully escaped HTML subset.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Quick_Answers {

	const VERB = 'ask.quick';

	/** Lines a list answer is cut to. */
	const LIST_LINES = 10;

	public static function init(): void {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ), 20 );
		add_filter( 'zdz_capability_annotations', array( __CLASS__, 'annotate' ) );
	}

	public static function register( $caps ) {
		$caps = is_array( $caps ) ? $caps : array();
		$caps[ self::VERB ] = array(
			'verb'        => self::VERB,
			'provider'    => 'sales-analytics',
			'app'         => ZANA_APP_ID,
			'tier'        => 'viewer', // each route decides who may
			'kiosk'       => false,
			'side_effect' => false,
			'outside'     => false,
			'summary'     => 'Answer a question from stored data when one of the ledger\'s rule routes takes it (no model).',
			'callback'    => array( __CLASS__, 'invoke' ),
		);
		return $caps;
	}

	/** Needs the chat app, as the chat does; each route then decides who may. */
	public static function annotate( $a ) {
		$a = is_array( $a ) ? $a : array();
		$a[ self::VERB ] = array( 'app' => ZANA_APP_ID, 'outside' => false, 'needs_app' => true, 'self_gated' => true, 'side_effect' => false );
		return $a;
	}

	/** The registry's call: args { question, user_id (stamped by the registry), is_kiosk }. */
	public static function invoke( $args ): array {
		$args = is_array( $args ) ? $args : array();
		$uid  = (int) ( $args['user_id'] ?? 0 );
		if ( ! empty( $args['is_kiosk'] ) ) {
			return array( 'success' => true, 'answered' => false, 'reason' => 'kiosk' );
		}
		return self::answer( (string) ( $args['question'] ?? ( $args['query'] ?? '' ) ), $uid );
	}

	/** The answer, or answered:false. { success, answered, kind, markdown, html, ms }. */
	public static function answer( string $question, int $uid ): array {
		$t0    = microtime( true );
		$q     = trim( (string) preg_replace( '/\s+/', ' ', $question ) );
		$no    = array( 'success' => true, 'answered' => false );
		$fence = class_exists( 'ZDZ_Ask_Context' ) && defined( 'ZDZ_Ask_Context::FENCE_START' ) ? ZDZ_Ask_Context::FENCE_START : 'ZDZ-DATA-START';
		if ( '' === $q || strlen( $q ) > 400 || $uid <= 0 || false !== stripos( $q, $fence ) ) {
			return $no;
		}
		$ctx = ZANA_Ledger_Access::context( $uid );
		$why = ZANA_Ledger_Access::why_not( $ctx, 'leads' );
		if ( in_array( $why, array( 'signed_out', 'kiosk', 'demo' ), true ) ) {
			return $no + array( 'reason' => $why );
		}
		$ledger = '' === $why; // the ledger's own routes need the staff or admin tier; an added route decides for itself
		$kind   = '';
		$md     = '';
		try {
			$bg = $ledger ? ZANA_Billing_Gaps::detect( $q ) : '';
			$ls = $ledger && '' === $bg ? ZANA_Lead_Sources::detect( $q ) : null;
			$la = $ledger && '' === $bg && null === $ls ? ZANA_Ledger_Answers::resolve( $q, $uid ) : null;
			if ( '' !== $bg ) {
				if ( 'accepted' !== $bg ) {
					return $no; // done-but-not-invoiced joins the jobs app's work: the chat's
				}
				$kind = 'billing_gaps';
				$md   = self::first_lines( ZANA_Billing_Gaps::accepted_answer( $uid, $q ), self::LIST_LINES );
			} elseif ( null !== $ls ) {
				if ( ! ZANA_Lead_Sources::ready() ) {
					return $no;
				}
				$kind = 'lead_sources';
				$md   = ZANA_Lead_Sources::quick( $ls, $ctx );
			} elseif ( null !== $la ) {
				if ( ! empty( $la['list'] ) ) {
					return $no; // a list is the chat's to show
				}
				$kind = 'ledger';
				$md   = ZANA_Ledger_Answers::quick( $la, $uid );
			} else {
				$routes = function_exists( 'apply_filters' ) ? apply_filters( 'zana_quick_routes', array() ) : array();
				foreach ( is_array( $routes ) ? $routes : array() as $route ) {
					if ( ! is_callable( $route ) ) {
						continue;
					}
					$r = call_user_func( $route, $q, $uid );
					if ( is_array( $r ) && isset( $r[0], $r[1] ) && '' !== trim( (string) $r[1] ) ) {
						$kind = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $r[0] ) );
						$md   = (string) $r[1];
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Analytics: a quick answer failed (' . get_class( $e ) . '); the ask bar offers the chat.' );
			return $no;
		}
		$md = trim( $md );
		if ( '' === $kind || '' === $md ) {
			return $no;
		}
		return array(
			'success'  => true,
			'answered' => true,
			'kind'     => $kind,
			'markdown' => $md,
			'html'     => self::html( $md ),
			'ms'       => (int) round( 1000 * ( microtime( true ) - $t0 ) ),
		);
	}

	/** The first $n non-empty lines of a markdown answer (never a table's header without its rows), then "…". */
	public static function first_lines( string $md, int $n ): string {
		$out  = array();
		$seen = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', $md ) as $l ) {
			if ( '' !== trim( $l ) ) {
				$seen++;
			}
			if ( $seen > $n && '' !== trim( $l ) ) {
				$out[] = '';
				$out[] = '…';
				break;
			}
			$out[] = $l;
		}
		while ( $out && preg_match( '/^\|?\s*:?-{2,}/', trim( (string) end( $out ) ) ) ) {
			array_pop( $out );
		}
		return trim( implode( "\n", $out ) );
	}

	/* ─────────────────────────── markdown -> html (the routes' own subset) ─────────────────────────── */

	protected static function esc( $s ): string {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	/** Inline marks on escaped text: **bold**, _italic_ / *italic*, `code`. Links are never rendered. */
	public static function inline( $s ): string {
		$s = self::esc( $s );
		$s = (string) preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s );
		$s = (string) preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $s );
		$s = (string) preg_replace( '/(?<![\w])_(?!\s)(.+?)(?<!\s)_(?![\w])/s', '<em>$1</em>', $s );
		return (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $s );
	}

	/** Paragraphs, "- " lists, "| a | b |" tables, "#" headings, bold, italic. Every character of content is escaped first. */
	public static function html( string $md ): string {
		$out   = array();
		$para  = array();
		$list  = array();
		$table = array();
		$flush = function () use ( &$out, &$para, &$list, &$table ) {
			if ( $para ) {
				$out[] = '<p>' . implode( '<br>', array_map( array( __CLASS__, 'inline' ), $para ) ) . '</p>';
				$para  = array();
			}
			if ( $list ) {
				$out[] = '<ul>' . implode( '', array_map( function ( $l ) { return '<li>' . ZANA_Quick_Answers::inline( $l ) . '</li>'; }, $list ) ) . '</ul>';
				$list  = array();
			}
			if ( $table ) {
				$rows = array();
				$head = true;
				$alg  = array();
				foreach ( $table as $r ) {
					if ( preg_match( '/^\|?\s*:?-{2,}/', trim( $r ) ) ) {
						foreach ( array_map( 'trim', explode( '|', trim( trim( $r ), '|' ) ) ) as $i => $c ) {
							$alg[ $i ] = ( ':' === substr( $c, -1 ) ) ? ' class="num"' : '';
						}
					}
				}
				foreach ( $table as $r ) {
					if ( preg_match( '/^\|?\s*:?-{2,}/', trim( $r ) ) ) {
						continue;
					}
					$cells  = array_map( 'trim', explode( '|', trim( trim( $r ), '|' ) ) );
					$tag    = $head ? 'th' : 'td';
					$cols   = array();
					foreach ( $cells as $i => $c ) {
						$cols[] = '<' . $tag . ( $alg[ $i ] ?? '' ) . '>' . ZANA_Quick_Answers::inline( $c ) . '</' . $tag . '>';
					}
					$rows[] = '<tr>' . implode( '', $cols ) . '</tr>';
					$head   = false;
				}
				$out[] = '<table>' . implode( '', $rows ) . '</table>';
				$table = array();
			}
		};
		foreach ( preg_split( '/\r\n|\r|\n/', $md ) as $l ) {
			$t = trim( $l );
			if ( '' === $t ) {
				$flush();
				continue;
			}
			if ( '|' === $t[0] ) {
				if ( $para || $list ) {
					$keep  = $table;
					$table = array();
					$flush();
					$table = $keep;
				}
				$table[] = $t;
				continue;
			}
			if ( $table ) {
				$flush();
			}
			if ( preg_match( '/^[-*•]\s+(.*)$/u', $t, $m ) ) {
				if ( $para ) {
					$keep = $list;
					$list = array();
					$flush();
					$list = $keep;
				}
				$list[] = $m[1];
				continue;
			}
			if ( preg_match( '/^(#{1,4})\s+(.*)$/', $t, $m ) ) {
				$flush();
				$out[] = '<p class="h"><strong>' . self::inline( $m[2] ) . '</strong></p>';
				continue;
			}
			if ( $list ) {
				$flush();
			}
			$para[] = $t;
		}
		$flush();
		return implode( "\n", $out );
	}
}
