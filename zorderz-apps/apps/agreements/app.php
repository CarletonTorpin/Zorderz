<?php
/**
 * Module: Zorderz - Agreements
 * Description: The customer's online agreement at /agreement/<six words>/: drawn from an approved template, the payment
 *   schedule from the estimate, the right to cancel with its real deadline under the legal pack for the job's state,
 *   one typed name signing behind an emailed code, the signed PDF, a Cancel button, change orders, a paper signing
 *   recorded with photos of the signature page, and an append-only record with a keyed hash chain. Built switched off
 *   for customers: test mode until an administrator turns it on.
 * Version:     1.0.0
 * Author:      Zorderz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zorderz
 * Requires PHP: 8.0
 *
 * This is a bundled app module (loaded by zorderz-apps.php), not a standalone plugin.
 *
 * LEGAL PACKS: the law an agreement follows ships as selectable packs of public law under packs/ (us-federal, the
 * default for a business in the United States; us-ca, layered on it for a job in California). Each rule carries its
 * citation, the date it took effect and the date it was last checked. The Business Profile's legal.agreement_pack
 * chooses the pack; the job address's state adds the state layer. The packs and the bundled starter template are not
 * legal advice, and the settings screen says so: a business has its own lawyer review them and write its terms.
 *
 * THE SEAM: the estimate app calls ZAGR_API behind class_exists( 'ZAGR_API' ) (ensure, contractor_sign, status_for,
 * schedule_for, link_for, record_paper, paper_for) and answers the filter zagr_estimate_salespeople. Other apps read
 * the verbs agreements.status_for and agreements.schedule_for on the platform's verb registry.
 *
 * CORE-SERVICE BINDINGS (each behind class_exists, so this app works alone and lights up as they are present):
 *   ZDZ_Business_Profile   the legal pack choice, the business's name, address, phone, logo, brand ramp, sender and
 *                          time zone
 *   ZDZ_Capabilities       the verbs (zdz_register_capabilities) and the tier ladder
 *   ZDZ_Alert_Router       the staff alerts (zdz_register_alert_types)
 *   ZDZ_User_Media         the photos of a paper signature page
 *   ZDZ_Kiosk_Demo, ZDZ_User_Roles   the shared-device and Demo Mode refusals
 *   ZKV_Private            the knowledge app's private documents (apps/knowledge), for a second encrypted copy of
 *                          a signed PDF; the record says whether it was kept and, if not, why (ZAGR_Storage)
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZAGR_VERSION', '1.0.0' );
define( 'ZAGR_FILE', __FILE__ );
define( 'ZAGR_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZAGR_URL', plugin_dir_url( __FILE__ ) );
define( 'ZAGR_APP_ID', 'agreements' );

require_once ZAGR_DIR . 'includes/class-zagr-clock.php';
require_once ZAGR_DIR . 'includes/class-zagr-business-days.php';
require_once ZAGR_DIR . 'includes/class-zagr-calendar.php';
require_once ZAGR_DIR . 'includes/class-zagr-strings.php';
require_once ZAGR_DIR . 'includes/class-zagr-words.php';
require_once ZAGR_DIR . 'includes/class-zagr-key.php';
require_once ZAGR_DIR . 'includes/class-zagr-template.php';
require_once ZAGR_DIR . 'includes/class-zagr-pack.php';
require_once ZAGR_DIR . 'includes/class-zagr-checklist.php';
require_once ZAGR_DIR . 'includes/class-zagr-settings.php';
require_once ZAGR_DIR . 'includes/class-zagr-install.php';
require_once ZAGR_DIR . 'includes/class-zagr-events.php';
require_once ZAGR_DIR . 'includes/class-zagr-store.php';
require_once ZAGR_DIR . 'includes/class-zagr-document.php';
require_once ZAGR_DIR . 'includes/class-zagr-pdf.php';
require_once ZAGR_DIR . 'includes/class-zagr-pdf-paper.php';
require_once ZAGR_DIR . 'includes/class-zagr-pdf-render.php';
require_once ZAGR_DIR . 'includes/class-zagr-storage.php';
require_once ZAGR_DIR . 'includes/class-zagr-mailer.php';
require_once ZAGR_DIR . 'includes/class-zagr-codes.php';
require_once ZAGR_DIR . 'includes/class-zagr-people.php';
require_once ZAGR_DIR . 'includes/class-zagr-alerts.php';
require_once ZAGR_DIR . 'includes/class-zagr-paper.php';
require_once ZAGR_DIR . 'includes/class-zagr-api.php';
require_once ZAGR_DIR . 'includes/class-zagr-verbs.php';
require_once ZAGR_DIR . 'includes/class-zagr-flow.php';
require_once ZAGR_DIR . 'includes/class-zagr-page.php';
require_once ZAGR_DIR . 'includes/class-zagr-public.php';
require_once ZAGR_DIR . 'includes/class-zagr-admin.php';
require_once ZAGR_DIR . 'includes/class-zagr-identity.php';

// The business's legal pack, agreement facts and lead times, from an Identity Pack (preview, apply, revert).
add_filter( 'zdz_identity_pack_consumers', array( 'ZAGR_Identity', 'register' ) );

ZAGR_Install::boot();
ZAGR_Verbs::boot();
ZAGR_Alerts::boot();
ZAGR_Public::boot();
ZAGR_Admin::boot();

/** First-run work, called by the bundle's activation (zorderz-apps.php). */
function zagr_activate() {
	ZAGR_Install::activate();
}

/** Stops the hourly sweep. Nothing is removed: the record is the business's. */
function zagr_deactivate() {
	ZAGR_Install::deactivate();
}

/** The Business Profile offers the legal packs on disk as the choices for legal.agreement_pack. */
add_filter(
	'zdz_business_profile_choices',
	function ( $choices ) {
		$choices = is_array( $choices ) ? $choices : array();
		$list    = array( 'none' => __( 'None (no agreements)', 'zorderz' ) );
		foreach ( ZAGR_Pack::ids() as $id ) {
			try {
				$p = ZAGR_Pack::load( $id );
			} catch ( ZAGR_Pack_Error $e ) {
				continue;
			}
			if ( '' === (string) ( $p['meta']['layers_on'] ?? '' ) ) {   // a national pack; a state pack is added by the job's state
				$list[ $id ] = (string) ( $p['meta']['label'] ?? $id );
			} else {
				/* translators: %s: the pack's label */
				$list[ $id ] = sprintf( __( '%s (and its national pack)', 'zorderz' ), (string) ( $p['meta']['label'] ?? $id ) );
			}
		}
		$choices['legal.agreement_pack'] = $list;
		return $choices;
	}
);

/** The salt watch names what changes for agreements when the site's secret keys change. */
add_filter(
	'zdz_salt_watch_breaks',
	function ( $lines, $changed = array() ) {
		$lines = is_array( $lines ) ? $lines : array();
		if ( in_array( 'secure_auth', (array) $changed, true ) && ! defined( 'ZDZ_AGREEMENTS_KEY' ) && '' !== (string) ( ZAGR_Key::record()['sealed'] ?? '' ) ) {
			$lines[] = __( 'Agreements stop taking signatures until an administrator enters the agreements key again (Settings, Agreements) from the copy kept in a password manager.', 'zorderz' );
		}
		return $lines;
	},
	10,
	2
);

/**
 * The tile: administrators open the agreements settings from the dashboard. Customers never see the app; they open
 * their agreement at its six-word link.
 */
add_action(
	'after_setup_theme',
	function () {
		if ( ! interface_exists( '\Zorderz\App_Interface' ) ) {
			return;
		}
		if ( ! class_exists( 'ZAGR_App' ) ) {
			class ZAGR_App implements \Zorderz\App_Interface {
				public function get_config(): array {
					return array(
						'id'          => ZAGR_APP_ID,
						'nm'          => __( 'Agreements', 'zorderz' ),
						'icon'        => 'file-signature',
						'cat'         => 'sales',
						'cc'          => '#0072B2',
						'desc'        => __( 'Online agreements, signatures, cancellations and paper signings.', 'zorderz' ),
						'roles'       => (array) apply_filters( 'zagr_app_roles', array( 'administrator', 'zdz_owner', 'zdz_admin' ) ),
						'bridge_type' => 'redirect',
						'admin_url'   => admin_url( 'options-general.php?page=' . ZAGR_Admin::SLUG ),
					);
				}

				public function render_mobile_view( int $user_id ): void {
					echo '<p><a href="' . esc_url( admin_url( 'options-general.php?page=' . ZAGR_Admin::SLUG ) ) . '">' . esc_html__( 'Open the agreements settings', 'zorderz' ) . '</a></p>';
				}
			}
		}
		add_filter(
			'zdz_register_apps',
			function ( $apps ) {
				$apps[ ZAGR_APP_ID ] = new ZAGR_App();
				return $apps;
			}
		);
	}
);
