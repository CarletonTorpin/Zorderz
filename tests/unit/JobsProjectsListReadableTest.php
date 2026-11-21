<?php
/**
 * The Projects list can be read: in light, dark, system and sunlight, and with Larger text.
 *
 * The stylesheet is checked against the theme's own tokens, with no browser:
 *
 *   - every piece of text on the list asks for one of the theme's type roles (which follow the phone, the desktop
 *     and Larger text by themselves), the name staying the largest line on a card; the search box is never under
 *     16px and follows Larger text above it;
 *   - no font size in the stylesheet can come out under 14px, as a value or as the fallback of a token;
 *   - text takes an ink made for text: the ink that belongs ON a brand fill is used only there, a status colour is
 *     never an ink on a plain surface, and a chip takes a tint pair, background and ink together;
 *   - worked out from the theme's tokens, every line of a card, the list's buttons and the lines of an opened
 *     Project read at 4.5:1 or better in each of the four display modes;
 *   - a long name or address wraps inside its card, and a panel taken off the screen stays off it.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class JobsProjectsListReadableTest extends TestCase {

	/** The surface the hub is drawn on, in the dashboard and full screen alike. */
	const SURFACE = '--sys-surface';

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	private static function sheet(): string {
		return (string) file_get_contents( self::root() . '/zorderz-apps/apps/jobs/assets/css/projects.css' );
	}

	/** selector => [ property => value ], later rules winning; a selector list is split at its top-level commas. */
	private static function rules(): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', self::sheet() );
		$out = array();
		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER );
		foreach ( $m as $r ) {
			$decl = array();
			foreach ( explode( ';', $r[2] ) as $d ) {
				if ( false !== strpos( $d, ':' ) ) {
					list( $k, $v )       = explode( ':', $d, 2 );
					$decl[ trim( $k ) ] = trim( $v );
				}
			}
			foreach ( self::split_top( $r[1] ) as $sel ) {
				$sel         = trim( (string) preg_replace( '/\s+/', ' ', $sel ) );
				$out[ $sel ] = array_merge( $out[ $sel ] ?? array(), $decl );
			}
		}
		return $out;
	}

	/** Split at commas that are not inside brackets. */
	private static function split_top( string $s ): array {
		$out   = array();
		$depth = 0;
		$cur   = '';
		foreach ( str_split( $s ) as $ch ) {
			if ( '(' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch ) {
				$depth--;
			}
			if ( ',' === $ch && 0 === $depth ) {
				$out[] = $cur;
				$cur   = '';
				continue;
			}
			$cur .= $ch;
		}
		$out[] = $cur;
		return array_values( array_filter( array_map( 'trim', $out ), 'strlen' ) );
	}

	/** The first token a value asks for: "var(--a, var(--b))" is "--a"; a plain value is itself. */
	private static function first_token( string $value ): string {
		return preg_match( '/var\(\s*(--[a-z0-9-]+)/', $value, $m ) ? $m[1] : trim( $value );
	}

	/**
	 * The theme's colour tokens in each display mode, as a browser resolves them: the stylesheet's blocks for the
	 * whole page, in order, then the app shell's. "system" here is system on a dark device (on a light one it is light).
	 *
	 * @return array<string,array<string,string>> mode => token => #hex
	 */
	private static function theme_tokens(): array {
		$css  = (string) file_get_contents( self::root() . '/zorderz/style.css' ) . "\n" . (string) file_get_contents( self::root() . '/zorderz/assets/css/app.css' );
		$css  = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$ref  = array();
		$mode = array( 'light' => array(), 'dark' => array(), 'system' => array(), 'sunlight' => array() );
		preg_match_all( '/((?::root|html)(?:\[data-theme="[a-z]+"\])?(?:\s*,\s*(?::root|html)(?:\[data-theme="[a-z]+"\])?)*)\s*\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER );
		foreach ( $m as $b ) {
			preg_match_all( '/(--(?:ref|sys)-[a-z0-9-]+)\s*:\s*([^;]+);?/', $b[2], $d, PREG_SET_ORDER );
			if ( ! $d ) {
				continue;
			}
			$to = array();
			foreach ( self::split_top( $b[1] ) as $sel ) {
				if ( preg_match( '/\[data-theme="([a-z]+)"\]/', $sel, $t ) ) {
					$to[] = $t[1];
				} else {
					$to = array_merge( $to, array( 'light', 'dark', 'system', 'sunlight' ) ); // the page's defaults
				}
			}
			foreach ( $d as $one ) {
				if ( 0 === strpos( $one[1], '--ref-' ) ) {
					$ref[ $one[1] ] = trim( $one[2] );
					continue;
				}
				foreach ( array_unique( $to ) as $t ) {
					if ( isset( $mode[ $t ] ) ) {
						$mode[ $t ][ $one[1] ] = trim( $one[2] );
					}
				}
			}
		}
		foreach ( $mode as $t => $tokens ) {
			foreach ( $tokens as $k => $v ) {
				if ( preg_match( '/^var\((--ref-[a-z0-9-]+)\)$/', $v, $r ) ) {
					$v = $ref[ $r[1] ] ?? '';
				}
				$mode[ $t ][ $k ] = $v;
			}
		}
		return $mode;
	}

	private static function luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$lin = static function ( int $v ): float {
			$c = $v / 255;
			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};
		return 0.2126 * $lin( (int) hexdec( substr( $hex, 0, 2 ) ) ) + 0.7152 * $lin( (int) hexdec( substr( $hex, 2, 2 ) ) ) + 0.0722 * $lin( (int) hexdec( substr( $hex, 4, 2 ) ) );
	}

	private static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/* ── The theme's roles ────────────────────────────────────────────────── */

	public function test_the_theme_has_the_roles_the_list_asks_for(): void {
		$theme = (string) file_get_contents( self::root() . '/zorderz/style.css' );
		foreach ( array( '--zdz-type-body:var(--ref-font-base)', '--zdz-type-secondary:var(--ref-font-sm)', '--zdz-type-meta:var(--ref-font-xs)' ) as $role ) {
			$this->assertStringContainsString( $role, $theme );
		}
		$this->assertStringContainsString( ':root[data-zdz-textscale="lg"]', $theme, 'Larger text re-sets the scale the roles are tiers of' );
	}

	public function test_every_piece_of_text_on_the_list_asks_for_a_role(): void {
		$r = self::rules();
		$this->assertSame( 'var(--zdz-type-body, var(--ref-font-base, 17px))', $r['.zprj-bar']['--zprj-lt-name'] );
		$this->assertSame( 'var(--zdz-type-secondary, var(--ref-font-sm, 15px))', $r['.zprj-bar']['--zprj-lt-sub'] );
		$this->assertSame( 'var(--zdz-type-meta, var(--ref-font-xs, 14px))', $r['.zprj-bar']['--zprj-lt-meta'] );
		foreach ( array( '--zprj-lt-name', '--zprj-lt-sub', '--zprj-lt-meta' ) as $role ) {
			$this->assertSame( $r['.zprj-bar'][ $role ], $r['.zprj-list'][ $role ] ?? '', 'the cards read the same roles as the buttons above them' );
		}
		$this->assertSame( 'var(--zprj-lt-name)', $r['.zprj-list .zprj-card strong']['font-size'], 'the customer\'s name' );
		$this->assertSame( 'var(--zprj-lt-name)', $r['.zprj-list .zprj-empty']['font-size'], 'the line that says nothing is here' );
		$this->assertSame( 'var(--zprj-lt-sub)', $r['.zprj-bar .zprj-tab']['font-size'], 'which Projects' );
		$meta = '.zprj-list :is(.zprj-status, .zprj-addr, .zprj-chip, .zprj-code, .zprj-meta)';
		$this->assertSame( 'var(--zprj-lt-meta)', $r[ $meta ]['font-size'], 'everything else on a card' );
		$this->assertSame( 'max( 16px, var(--zprj-lt-meta) )', $r['.zprj-bar .zprj-search']['font-size'], 'never under 16px, and it follows Larger text' );
		// Every class a card is drawn with has its size from that block: nothing on the list is left to a px size.
		$js = (string) file_get_contents( self::root() . '/zorderz-apps/apps/jobs/assets/js/projects.js' );
		$at = (int) strpos( $js, 'function renderList(' );
		preg_match_all( '/class="(zprj-[a-z-]+)/', substr( $js, $at, (int) strpos( $js, '/* ---- detail' ) - $at ), $used );
		$sized = array( 'zprj-status', 'zprj-addr', 'zprj-chip', 'zprj-code', 'zprj-meta', 'zprj-empty' );
		$boxes = array( 'zprj-card', 'zprj-card-top', 'zprj-card-foot' ); // they hold lines, they are not one
		foreach ( array_unique( $used[1] ) as $class ) {
			$this->assertContains( $class, array_merge( $sized, $boxes ), $class . ' is drawn on the list: give it a role' );
		}
	}

	public function test_no_font_size_can_come_out_under_14px(): void {
		foreach ( self::rules() as $sel => $decl ) {
			foreach ( $decl as $prop => $value ) {
				if ( 'font-size' !== $prop && 0 !== strpos( $prop, '--zprj-lt-' ) && 'font' !== $prop ) {
					continue;
				}
				preg_match_all( '/(\d+(?:\.\d+)?)px/', $value, $px );
				foreach ( $px[1] as $n ) {
					$this->assertGreaterThanOrEqual( 14, (float) $n, $sel . ' { ' . $prop . ': ' . $value . ' }' );
				}
				$this->assertDoesNotMatchRegularExpression( '/\d(?:em|%)|x?x-small|small/', (string) preg_replace( '/[\d.]+rem/', '', $value ), $sel . ' asks for a size smaller than its parent' );
			}
		}
	}

	/* ── Ink ──────────────────────────────────────────────────────────────── */

	public function test_the_ink_for_a_brand_fill_is_used_only_on_one(): void {
		$on_brand = 0;
		foreach ( self::rules() as $sel => $decl ) {
			$ink = self::first_token( $decl['color'] ?? '' );
			if ( '--sys-text-brand' === $ink ) {
				$this->assertSame( 'var(--sys-brand)', $decl['background'] ?? '', $sel . ' puts the brand fill\'s ink on something else: it is made to read on the brand fill only' );
				$on_brand++;
			}
			$this->assertNotContains( $ink, array( '--sys-warning', '--sys-error', '--sys-success', '--sys-on-brand', '--sys-warning-text' ), $sel . ' uses a status colour (or a token the theme does not have) as ink' );
		}
		$this->assertSame( 2, $on_brand, 'the selected button and the primary button' );
	}

	public function test_a_chip_takes_a_tint_pair_background_and_ink_together(): void {
		$r = self::rules();
		foreach ( array( '.zprj-chip' => 'neutral', '.zprj-chip-scheduled' => 'info', '.zprj-chip-unscheduled' => 'warning', '.zprj-gate.is-ok' => 'success', '.zprj-gate.is-wait' => 'warning' ) as $sel => $pair ) {
			$this->assertSame( '--sys-tint-' . $pair . '-bg', self::first_token( $r[ $sel ]['background'] ), $sel );
			$this->assertSame( '--sys-tint-' . $pair . '-fg', self::first_token( $r[ $sel ]['color'] ), $sel );
		}
		// Sunlight draws its tints as an outline: the chip and the status box carry the theme's tint border.
		$this->assertSame( '1px solid var(--sys-tint-border, transparent)', $r['.zprj-chip']['border'] );
		$this->assertSame( '1px solid var(--sys-tint-border, transparent)', $r['.zprj-gate']['border'] );
	}

	/**
	 * Each line a person reads, as [ the rule its ink comes from, the rule (or token) its background comes from ].
	 */
	public function lines(): array {
		return array(
			'the customer\'s name on a card'  => array( '.zprj-card', '.zprj-card' ),
			'where the Project stands'        => array( '.zprj-status', '.zprj-card' ),
			'the address and the small lines' => array( '.zprj-addr', '.zprj-card' ),
			'the install chip'                => array( '.zprj-chip', '.zprj-chip' ),
			'the install chip, scheduled'     => array( '.zprj-chip-scheduled', '.zprj-chip-scheduled' ),
			'the install chip, unscheduled'   => array( '.zprj-chip-unscheduled', '.zprj-chip-unscheduled' ),
			'which Projects (not selected)'   => array( '.zprj-tab', '.zprj-tab' ),
			'the search box'                  => array( '.zprj-search', '.zprj-search' ),
			'nothing here yet'                => array( '.zprj-empty', self::SURFACE ),
			'back to the list'                => array( '.zprj-back', self::SURFACE ),
			'where it stands, opened'         => array( '.zprj-status', self::SURFACE ),
			'OK to order'                     => array( '.zprj-gate.is-ok', '.zprj-gate.is-ok' ),
			'orders wait'                     => array( '.zprj-gate.is-wait', '.zprj-gate.is-wait' ),
			'a section\'s heading'            => array( '.zprj-sec h4', self::SURFACE ),
			'a step done'                     => array( '.zprj-steps span.is-done', '.zprj-steps span' ),
			'a step not done'                 => array( '.zprj-steps span', '.zprj-steps span' ),
			'why an order waits'              => array( '.zprj-why', self::SURFACE ),
			'what went wrong'                 => array( '.zprj-msg', self::SURFACE ),
			'what a form could not take'      => array( '.zprj-problems', self::SURFACE ),
			'a button'                        => array( '.zprj-btn', '.zprj-btn' ),
			'a quiet button'                  => array( '.zprj-btn-ghost', '.zprj-btn' ),
			'the order line'                  => array( '.zprj-ord-morebtn', self::SURFACE ),
			'the estimate\'s notes'           => array( '.zprj-said p', self::SURFACE ),
			'what the notes are'              => array( '.zprj-said-h', self::SURFACE ),
		);
	}

	public function test_every_line_reads_at_4_5_to_1_or_better_in_all_four_display_modes(): void {
		$r      = self::rules();
		$themes = self::theme_tokens();
		$this->assertSame( '#E9EEF5', $themes['light']['--sys-bg'], 'the app shell\'s own light background is the one a browser uses' );
		$this->assertSame( '#000000', $themes['sunlight']['--sys-text'] );
		foreach ( $this->lines() as $what => $pair ) {
			$this->assertArrayHasKey( $pair[0], $r, $what );
			$ink = self::first_token( $r[ $pair[0] ]['color'] ?? '' );
			$bg  = 0 === strpos( $pair[1], '--' ) ? $pair[1] : self::first_token( $r[ $pair[1] ]['background'] ?? '' );
			$this->assertSame( 0, strpos( $ink, '--sys-' ), $what . ': its ink is a theme token' );
			$this->assertSame( 0, strpos( $bg, '--sys-' ), $what . ': its background is a theme token' );
			foreach ( $themes as $mode => $tokens ) {
				$this->assertArrayHasKey( $ink, $tokens, $ink . ' in ' . $mode );
				$this->assertArrayHasKey( $bg, $tokens, $bg . ' in ' . $mode );
				$ratio = self::contrast( $tokens[ $ink ], $tokens[ $bg ] );
				$this->assertGreaterThanOrEqual( 4.5, $ratio, sprintf( '%s in %s: %s on %s is %.2f:1', $what, $mode, $ink, $bg, $ratio ) );
			}
		}
	}

	public function test_the_selected_button_is_the_themes_own_brand_pair(): void {
		// The ink on a brand fill is the theme's to choose: the hub uses the pair every brand button of the theme uses.
		$r = self::rules();
		$this->assertSame( 'var(--sys-brand)', $r['.zprj-tab.is-active']['background'] );
		$this->assertSame( '--sys-text-brand', self::first_token( $r['.zprj-tab.is-active']['color'] ) );
		$app = (string) file_get_contents( self::root() . '/zorderz/assets/css/app.css' );
		$this->assertStringContainsString( '.btn-brand{background:var(--sys-brand);color:var(--sys-text-brand)', $app );
	}

	/* ── Layout ───────────────────────────────────────────────────────────── */

	public function test_a_long_name_or_address_wraps_inside_its_card(): void {
		$r = self::rules();
		$this->assertSame( 'wrap', $r['.zprj-list .zprj-card-top']['flex-wrap'], 'the status drops under a long name instead of leaving the card' );
		$this->assertSame( '0', $r['.zprj-list .zprj-card strong']['min-width'] );
		$this->assertSame( 'anywhere', $r['.zprj-list :is(.zprj-card strong, .zprj-addr, .zprj-code, .zprj-meta)']['overflow-wrap'] );
		$this->assertSame( 'wrap', $r['.zprj-card-foot']['flex-wrap'] );
		$this->assertSame( 'wrap', $r['.zprj-bar']['flex-wrap'] );
	}

	public function test_a_panel_taken_off_the_screen_stays_off_it(): void {
		$r = self::rules();
		foreach ( array( '.zprj-bar', '.zprj-list', '.zprj-desk' ) as $panel ) {
			$this->assertSame( 'flex', $r[ $panel ]['display'], $panel . ' has a display of its own' );
			$this->assertSame( 'none', $r[ $panel . '[hidden]' ]['display'] ?? '', $panel . ': so the hidden attribute needs a rule to win' );
		}
		$this->assertSame( 'none', $r['.zprj-detail[hidden]']['display'] );
		$js = (string) file_get_contents( self::root() . '/zorderz-apps/apps/jobs/assets/js/projects.js' );
		$this->assertStringContainsString( "root.querySelector('.zprj-list').hidden = true;", $js, 'opening a Project takes the list off the screen' );
		$this->assertStringContainsString( "root.querySelector('.zprj-bar').hidden = true;", $js );
	}
}
