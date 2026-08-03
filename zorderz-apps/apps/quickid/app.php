<?php
/**
 * Plugin Name: Zorderz Quick-ID
 * Description: Swipe from the left edge of the homepage to slide in a full-screen, photographable digital business card for the signed-in person. The card face is built from the Business Profile (logo, phone, licence, former-name line, promo banner) and the person's own account.
 * Version: 1.6.0
 * Author: Zorderz
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 7.4
 *
 * ── What this is ──────────────────────────────────────────────────────
 * An OUTPUT-ONLY feature: no AJAX, no REST, no admin-post, no nonces in the
 * DOM. The card shows only the CURRENTLY SIGNED-IN person's own identity,
 * rendered server-side into the homepage footer (INV-1: nothing here trusts
 * the client). The shared-device / kiosk tier never shows a personal card
 * (INV-10), resolved through the theme's own kiosk predicate rather than a
 * hardcoded role slug.
 *
 * ── Why there is no springboard tile ──────────────────────────────────
 * Unlike the other bundled apps, Quick-ID is not a dashboard tile and does
 * NOT register through the `zdz_register_apps` filter. It is an ambient
 * homepage gesture available to EVERY signed-in, non-kiosk person with no
 * app grant required — turning it into a grant-gated tile would REDUCE who
 * can reach their own card, the opposite of its purpose. It still loads as a
 * bundle module (via the apps manifest) and defers all of its work to
 * `after_setup_theme`, matching the bundle's load-order discipline, and it
 * declines to run at all when the theme (ZDZ_Business_Profile) is absent.
 *
 * ── Where the identity comes from (Core services, never hardcoded) ─────
 *   ZDZ_Business_Profile : logo, company phone, licence line, former names,
 *                          the promo banner copy, the staff email pattern.
 *   ZDZ_Party / ZDZ_Hierarchy : who is a "person" vs the shared kiosk.
 *   The person's own WP account : name, first/last, account email.
 * Per-person card overrides (name / title / cell / email) live in user meta.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZQID_VERSION', '1.6.0' );
define( 'ZQID_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZQID_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Boot on after_setup_theme.
 *
 * WordPress loads plugins before themes, so the theme's Core services do not
 * exist yet at file-load time. Every other bundled app defers its real work to
 * after_setup_theme for the same reason; this one gates on the Business Profile
 * service specifically, because without it there is no identity to render and a
 * hardcoded fallback is exactly what this port removes. No profile → no card,
 * quietly (the swipe simply does nothing) rather than a fatal.
 */
add_action(
	'after_setup_theme',
	function () {
		if ( ! class_exists( 'ZDZ_Business_Profile' ) ) {
			return;
		}
		ZQID_Card::instance();
	}
);

final class ZQID_Card {

	/** @var ZQID_Card|null */
	private static $instance = null;

	/**
	 * Per-person card override meta keys, mapped to their deprecated
	 * predecessors. The new `zqid_*` keys are authoritative; the legacy
	 * `tsqid_*` keys are read as a fallback and migrated on the next profile
	 * save, so an install upgraded from the pre-rename lineage keeps its
	 * per-person card data. (Successor for the deprecated `tsqid_*` keys.)
	 *
	 * @var array<string,string>
	 */
	private static $card_meta = array(
		'zqid_name'  => 'tsqid_name',
		'zqid_title' => 'tsqid_title',
		'zqid_cell'  => 'tsqid_cell',
		'zqid_email' => 'tsqid_email',
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render_overlay' ), 50 );

		// Per-person card fields on the profile screens (no custom pages, no new caps).
		add_action( 'show_user_profile', array( $this, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Eligibility                                                        */
	/* ------------------------------------------------------------------ */

	private function should_load() {
		if ( ! is_user_logged_in() || ! is_front_page() ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		// The shared device / kiosk is never a person and never shows a personal
		// card (INV-10). Resolved through the theme, not a role literal.
		if ( $this->is_shared_device( $user ) ) {
			return false;
		}
		/**
		 * Filter: allow narrowing/widening who gets the Quick-ID card.
		 *
		 * @param bool    $show Default true for any signed-in, non-kiosk person on the homepage.
		 * @param WP_User $user Current user.
		 */
		return (bool) apply_filters( 'zqid_should_load', true, $user );
	}

	/**
	 * Is this account the shared-device / kiosk tier? Delegated to the theme so
	 * the privacy floor survives a role rename (a role slug is never matched as a
	 * literal for this decision — see the Party model's NEVER_PARTY_ROLES).
	 */
	private function is_shared_device( $user ) {
		$uid = (int) $user->ID;
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) ) {
			return (bool) ZDZ_Hierarchy::is_kiosk( $uid );
		}
		if ( class_exists( 'ZDZ_Party' ) && defined( 'ZDZ_Party::NEVER_PARTY_ROLES' ) ) {
			return (bool) array_intersect( (array) $user->roles, (array) ZDZ_Party::NEVER_PARTY_ROLES );
		}
		return in_array( 'zdz_general', (array) $user->roles, true );
	}

	/* ------------------------------------------------------------------ */
	/*  Card data                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * A per-person card meta value, preferring the current `zqid_*` key and
	 * falling back to the deprecated `tsqid_*` key.
	 */
	private function person_meta( $user_id, $key ) {
		$val = trim( (string) get_user_meta( $user_id, $key, true ) );
		if ( '' === $val && isset( self::$card_meta[ $key ] ) ) {
			$val = trim( (string) get_user_meta( $user_id, self::$card_meta[ $key ], true ) );
		}
		return $val;
	}

	/**
	 * Company-wide card values, read from the Business Profile.
	 *
	 * Everything here was a hardcoded literal in the source plugin (phone,
	 * licence number, an absolute logo URL, a "formerly …" line, a place-specific
	 * promo banner, the staff email domain). Each now resolves from Core config
	 * and ships EMPTY, so a fresh install renders a coherent blank-slate card and
	 * a business lights the fields up by filling in its profile.
	 *
	 * @return array
	 */
	private function company_card() {
		$bp = 'ZDZ_Business_Profile';

		$phone   = (string) $bp::get( 'contact.phone', '' );
		$licence = (string) $bp::get( 'identity.license', '' );

		// The "Formerly ‘…’" line renders only when the business has a former
		// name on file (BP-04); empty list ⇒ no line.
		$former      = (array) $bp::get( 'identity.former_names', array() );
		$former_name = '';
		foreach ( $former as $fn ) {
			$fn = trim( (string) $fn );
			if ( '' !== $fn ) {
				$former_name = $fn;
				break;
			}
		}

		// Promotional banner across the wave (BP-15). Two optional copy slots;
		// each renders only when set. Pack path: brand.taglines.card_banner_*.
		$banner_top = (string) $bp::get( 'brand.taglines.card_banner_top', '' );
		$banner_big = (string) $bp::get( 'brand.taglines.card_banner_big', '' );

		// Logo: the business's wide lockup for a light (white) card face; the
		// method picks the correct ink and falls back across shapes. Empty URL
		// ⇒ the card renders the business name as a text wordmark instead of a
		// hardcoded image URL.
		$logo = $bp::logo( 'wide', 'light' );

		return array(
			'business_name' => (string) $bp::name(),
			'logo_url'      => (string) ( $logo['url'] ?? '' ),
			'main_phone'    => $phone,
			'licence'       => $licence,
			'former_name'   => $former_name,
			'banner_top'    => $banner_top,
			'banner_big'    => $banner_big,
			'email_pattern' => (string) $bp::get( 'people.staff_email_pattern', '' ),
		);
	}

	private function card_data( $user ) {
		$company = $this->company_card();

		// Card name: a per-person override, else the print-card "First L." form
		// derived from the account, else the display name.
		$name = $this->person_meta( $user->ID, 'zqid_name' );
		if ( '' === $name ) {
			$first = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
			$last  = trim( (string) get_user_meta( $user->ID, 'last_name', true ) );
			if ( '' !== $first && '' !== $last ) {
				$name = $first . ' ' . strtoupper( mb_substr( $last, 0, 1 ) ) . '.';
			} else {
				$name = $user->display_name;
			}
		}

		$data = array(
			'name'  => $name,
			'title' => $this->person_meta( $user->ID, 'zqid_title' ),
			'cell'  => $this->person_meta( $user->ID, 'zqid_cell' ),
			'email' => $this->card_email( $user, $company['email_pattern'] ),
		) + $company;

		/**
		 * Filter: final card fields before render. All values are escaped at output.
		 *
		 * @param array   $data name/title/cell/email + the company card fields.
		 * @param WP_User $user
		 */
		return apply_filters( 'zqid_card_data', $data, $user );
	}

	/**
	 * The card email address.
	 *
	 *   1. A per-person override, if set.
	 *   2. Otherwise the business's staff email PATTERN (e.g. "{first}@acme.com")
	 *      with the person's name substituted — the generalized replacement for
	 *      the old hardcoded "FirstName@<company>.com" rule. Used only if it
	 *      fully resolves to a valid address.
	 *   3. Otherwise the person's own account email.
	 *
	 * The pattern is a SUGGESTION derived from config, never an assumption baked
	 * into code, and there is no company/person example anywhere in this method.
	 */
	private function card_email( $user, $pattern ) {
		$override = $this->person_meta( $user->ID, 'zqid_email' );
		if ( '' !== $override ) {
			return $override;
		}

		$pattern = trim( (string) $pattern );
		if ( '' !== $pattern && false !== strpos( $pattern, '{' ) ) {
			$first = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
			$last  = trim( (string) get_user_meta( $user->ID, 'last_name', true ) );
			if ( '' === $first ) {
				$first = trim( (string) strtok( (string) $user->display_name, ' ' ) );
			}
			$first = preg_replace( '/[^A-Za-z0-9.\-]/', '', $first );
			$last  = preg_replace( '/[^A-Za-z0-9.\-]/', '', $last );
			if ( '' !== $first ) {
				$addr = strtr(
					$pattern,
					array(
						'{first}' => ucfirst( $first ),
						'{last}'  => ucfirst( $last ),
						'{f}'     => strtoupper( substr( $first, 0, 1 ) ),
						'{l}'     => '' !== $last ? strtoupper( substr( $last, 0, 1 ) ) : '',
					)
				);
				// Use it only if the template fully resolved and is a real address.
				if ( false === strpos( $addr, '{' ) && is_email( $addr ) ) {
					return $addr;
				}
			}
		}

		return (string) $user->user_email;
	}

	/**
	 * Render a phone number as a tel: link (styled by CSS to keep the print
	 * look) — falls back to plain text if it doesn't parse.
	 */
	private function phone_html( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 10 === strlen( $digits ) ) {
			$digits = '1' . $digits;
		}
		if ( strlen( $digits ) < 10 ) {
			return esc_html( $phone );
		}
		return '<a href="' . esc_attr( 'tel:+' . $digits ) . '">' . esc_html( $phone ) . '</a>';
	}

	/* ------------------------------------------------------------------ */
	/*  Assets + markup                                                    */
	/* ------------------------------------------------------------------ */

	public function enqueue() {
		if ( ! $this->should_load() ) {
			return;
		}
		$css = 'assets/quick-id.css';
		$js  = 'assets/quick-id.js';

		wp_enqueue_style(
			'zqid',
			ZQID_PLUGIN_URL . $css,
			array(),
			ZQID_VERSION . '.' . (int) @filemtime( ZQID_PLUGIN_DIR . $css )
		);
		wp_enqueue_script(
			'zqid',
			ZQID_PLUGIN_URL . $js,
			array(),
			ZQID_VERSION . '.' . (int) @filemtime( ZQID_PLUGIN_DIR . $js ),
			true
		);
	}

	public function render_overlay() {
		if ( ! $this->should_load() ) {
			return;
		}
		$d           = $this->card_data( wp_get_current_user() );
		$has_banner  = ( '' !== $d['banner_top'] || '' !== $d['banner_big'] );
		$card_class  = ( '' === $d['title'] ? ' zqid-no-title' : '' ) . ( '' === $d['cell'] ? ' zqid-no-cell' : '' );
		?>
<div class="zqid-overlay zqid-closed" id="zqidOverlay" role="dialog" aria-modal="true"
     aria-label="<?php esc_attr_e( 'Business card', 'zorderz' ); ?>" aria-hidden="true">
  <div class="zqid-card<?php echo esc_attr( $card_class ); ?>" id="zqidCard">

    <?php if ( '' !== $d['logo_url'] ) : ?>
    <img class="zqid-logo" src="<?php echo esc_url( $d['logo_url'] ); ?>"
         alt="" aria-hidden="true" decoding="async">
    <?php else : ?>
    <div class="zqid-logo zqid-logo-text" aria-hidden="true"><?php echo esc_html( $d['business_name'] ); ?></div>
    <?php endif; ?>

    <?php if ( '' !== $d['former_name'] ) : ?>
    <div class="zqid-formerly"><?php
		/* translators: %s: a former business name, shown in single quotes on the card. */
		echo sprintf( esc_html__( 'Formerly %s', 'zorderz' ), '&#8216;' . esc_html( $d['former_name'] ) . '&#8217;' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- name is escaped; quotes are literal markup.
	?></div>
    <?php endif; ?>

    <div class="zqid-abs zqid-name"><?php echo esc_html( $d['name'] ); ?></div>
    <?php if ( '' !== $d['title'] ) : ?>
    <div class="zqid-abs zqid-title"><?php echo esc_html( $d['title'] ); ?></div>
    <?php endif; ?>
    <?php if ( '' !== $d['main_phone'] ) : ?>
    <div class="zqid-abs zqid-main"><?php esc_html_e( 'Main:', 'zorderz' ); ?> <?php echo $this->phone_html( $d['main_phone'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- phone_html escapes. ?></div>
    <?php endif; ?>
    <?php if ( '' !== $d['cell'] ) : ?>
    <div class="zqid-abs zqid-cell"><?php esc_html_e( 'Cell:', 'zorderz' ); ?> <?php echo $this->phone_html( $d['cell'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- phone_html escapes. ?></div>
    <?php endif; ?>
    <div class="zqid-abs zqid-email"><a href="<?php echo esc_attr( 'mailto:' . $d['email'] ); ?>"><?php echo esc_html( $d['email'] ); ?></a></div>
    <?php if ( '' !== $d['licence'] ) : ?>
    <div class="zqid-abs zqid-license"><?php echo esc_html( $d['licence'] ); ?></div>
    <?php endif; ?>

    <svg class="zqid-wave" viewBox="0 0 1000 646" aria-hidden="true" preserveAspectRatio="none">
      <defs>
        <clipPath id="zqidWave">
          <path d="M0,48 C300,158 700,208 1000,2 L1000,646 L0,646 Z"/>
        </clipPath>
      </defs>
      <g clip-path="url(#zqidWave)">
        <rect x="0"   y="0" width="45"  height="646" fill="var(--zqid-s1)"/>
        <rect x="45"  y="0" width="305" height="646" fill="var(--zqid-s2)"/>
        <rect x="350" y="0" width="280" height="646" fill="var(--zqid-s3)"/>
        <rect x="630" y="0" width="325" height="646" fill="var(--zqid-s4)"/>
        <rect x="955" y="0" width="45"  height="646" fill="var(--zqid-s5)"/>
      </g>
      <?php if ( $has_banner ) : ?>
      <g transform="rotate(-5 500 330)">
        <?php if ( '' !== $d['banner_top'] ) : ?>
        <text x="500" y="262" text-anchor="middle" font-size="68" font-weight="700"
              font-style="italic" fill="#fff"><?php echo esc_html( $d['banner_top'] ); ?></text>
        <?php endif; ?>
        <?php if ( '' !== $d['banner_big'] ) : ?>
        <text x="500" y="432" text-anchor="middle" font-size="142" font-weight="900"
              letter-spacing="2" fill="var(--zqid-accent)" stroke="#000" stroke-width="16"
              stroke-linejoin="round" paint-order="stroke fill"
              textLength="884" lengthAdjust="spacingAndGlyphs"><?php echo esc_html( $d['banner_big'] ); ?></text>
        <?php endif; ?>
      </g>
      <?php endif; ?>
    </svg>

  </div>

  <button type="button" class="zqid-close" id="zqidClose"
          aria-label="<?php esc_attr_e( 'Close', 'zorderz' ); ?>">&#10005;</button>
  <div class="zqid-hint"><?php esc_html_e( 'Swipe left or tap \'X\' to close', 'zorderz' ); ?></div>
</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/*  Profile fields (title / cell / name / email override)              */
	/* ------------------------------------------------------------------ */

	public function profile_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$name  = $this->person_meta( $user->ID, 'zqid_name' );
		$title = $this->person_meta( $user->ID, 'zqid_title' );
		$cell  = $this->person_meta( $user->ID, 'zqid_cell' );
		$email = $this->person_meta( $user->ID, 'zqid_email' );
		?>
<h2><?php esc_html_e( 'Quick-ID Card', 'zorderz' ); ?></h2>
<table class="form-table" role="presentation">
  <tr>
    <th><label for="zqid_name"><?php esc_html_e( 'Card name (override)', 'zorderz' ); ?></label></th>
    <td>
      <input type="text" name="zqid_name" id="zqid_name" class="regular-text"
             value="<?php echo esc_attr( $name ); ?>">
      <p class="description"><?php esc_html_e( 'Leave blank to use "First L." from the profile name.', 'zorderz' ); ?></p>
    </td>
  </tr>
  <tr>
    <th><label for="zqid_title"><?php esc_html_e( 'Card title', 'zorderz' ); ?></label></th>
    <td><input type="text" name="zqid_title" id="zqid_title" class="regular-text"
             value="<?php echo esc_attr( $title ); ?>"
             placeholder="<?php esc_attr_e( 'Role or title', 'zorderz' ); ?>"></td>
  </tr>
  <tr>
    <th><label for="zqid_cell"><?php esc_html_e( 'Card cell number', 'zorderz' ); ?></label></th>
    <td><input type="text" name="zqid_cell" id="zqid_cell" class="regular-text"
             value="<?php echo esc_attr( $cell ); ?>"
             placeholder="<?php esc_attr_e( '(555) 555-0123', 'zorderz' ); ?>"></td>
  </tr>
  <tr>
    <th><label for="zqid_email"><?php esc_html_e( 'Card email (override)', 'zorderz' ); ?></label></th>
    <td>
      <input type="text" name="zqid_email" id="zqid_email" class="regular-text"
             value="<?php echo esc_attr( $email ); ?>"
             placeholder="<?php esc_attr_e( 'name@example.com', 'zorderz' ); ?>">
      <p class="description"><?php esc_html_e( 'Leave blank to use the business email pattern, or your account email.', 'zorderz' ); ?></p>
    </td>
  </tr>
</table>
		<?php
	}

	public function save_profile_fields( $user_id ) {
		// Core has already verified the update-user nonce before these hooks fire;
		// re-check capability for the target user.
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		foreach ( self::$card_meta as $key => $legacy ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$val = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			if ( '' === $val ) {
				delete_user_meta( $user_id, $key );
			} else {
				update_user_meta( $user_id, $key, $val );
			}
			// Retire the deprecated predecessor either way (one-time migration).
			delete_user_meta( $user_id, $legacy );
		}
	}
}
