<?php
/**
 * ZEST_Settings - the estimate app's rows on Zorderz -> Settings (administrators).
 *
 *   Estimates Ai road        zest_ai_route: the platform's Ai router (the default, when it is set up) or the chat
 *                            gateway, as ZEST_Router_Client reads it.
 *   Payment schedule notes   zest_payment_notes_mode: off / test (test customers only, the default) / on, as
 *                            ZEST_Terms_Note reads it.
 *   Customer lookup index    how far the copy of the billing system's client list is (ZEST_Client_Index), read only.
 *   Line choices             where the press-and-hold option map in use came from (ZEST_Line_Options), read only.
 *   Follow-up line           zest_followup_line_mode: off / test (test customers only, the default) / on, as
 *                            ZEST_Followup reads it: whether an answer writes its one fixed line onto the billing
 *                            estimate.
 *   Privacy View             zest_privacy_view_mode: off (the default) / look, as ZEST_Privacy_View reads it: whether
 *                            an account in Privacy View may look at Estimates.
 *   Customer link test       a check an administrator runs by hand (zest_test_estimate_link): does the billing system
 *                            give ONE test customer's estimate a link its customer can open? Nothing is saved.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Settings {

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'zdz_core_settings_rows', array( __CLASS__, 'rows' ) );
		add_action( 'wp_ajax_zest_test_estimate_link', array( __CLASS__, 'ajax_test_estimate_link' ) );
	}

	public static function register(): void {
		register_setting( 'zdz_core_settings_group', 'zest_ai_route', array(
			'type'              => 'string',
			'default'           => 'router',
			'sanitize_callback' => static function ( $v ) { return 'gateway' === $v ? 'gateway' : 'router'; },
		) );
		register_setting( 'zdz_core_settings_group', 'zest_payment_notes_mode', array(
			'type'              => 'string',
			'default'           => 'test',
			'sanitize_callback' => static function ( $v ) { return in_array( $v, array( 'off', 'test', 'on' ), true ) ? $v : 'test'; },
		) );
		register_setting( 'zdz_core_settings_group', 'zest_followup_line_mode', array(
			'type'              => 'string',
			'default'           => 'test',
			'sanitize_callback' => static function ( $v ) { return in_array( $v, array( 'off', 'test', 'on' ), true ) ? $v : 'test'; },
		) );
		register_setting( 'zdz_core_settings_group', 'zest_privacy_view_mode', array(
			'type'              => 'string',
			'default'           => 'off',
			'sanitize_callback' => static function ( $v ) { return 'look' === $v ? 'look' : 'off'; },
		) );
	}

	/* ---------------------------------------------------------------- the estimate customer link test */

	/**
	 * What the screen is told about a link test (ZEST_Billing_Doc::probe_customer_link()'s answer), in words. A
	 * customer link is passed on as text to copy; nothing else the billing system sent is.
	 */
	public static function link_test_reply( array $r ): array {
		if ( empty( $r['ok'] ) ) {
			$why = array(
				'bad_number' => __( 'Type an estimate number (digits only).', 'zorderz' ),
				'unread'     => __( 'The billing system could not be read just now. Try again in a minute.', 'zorderz' ),
				'not_found'  => __( 'The billing system has no estimate with that number.', 'zorderz' ),
				'not_test'   => __( 'That estimate is not a test customer\'s. This test runs only on a test customer\'s estimate, because the billing system may mark it sent.', 'zorderz' ),
			);
			return array( 'ok' => false, 'message' => $why[ (string) ( $r['why'] ?? '' ) ] ?? __( 'The test could not run.', 'zorderz' ) );
		}
		$gave  = array(
			'none'            => __( 'nothing', 'zorderz' ),
			'customer_link'   => __( 'a customer link', 'zorderz' ),
			'billing_address' => __( 'an address at the billing system that is not a customer link', 'zorderz' ),
			'bare'            => __( 'not an address (a bare value: never used)', 'zorderz' ),
		);
		$tries = array();
		$found = false;
		foreach ( (array) ( $r['probes'] ?? array() ) as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$is      = ! empty( $t['customer_link'] ) && 'customer_link' === ( $t['form'] ?? '' );
			$found   = $found || $is;
			$tries[] = array(
				'label' => (string) preg_replace( '/[^A-Za-z0-9._ ()-]/', '', (string) ( $t['label'] ?? '' ) ),
				'gave'  => $gave[ (string) ( $t['form'] ?? '' ) ] ?? $gave['bare'],
				'link'  => $is ? esc_url_raw( (string) ( $t['link'] ?? '' ), array( 'https' ) ) : '',
			);
		}
		return array(
			'ok'      => true,
			'number'  => (string) ( $r['number'] ?? '' ),
			'status'  => (string) ( $r['status'] ?? '' ),
			'tries'   => $tries,
			'found'   => $found,
			'message' => $found
				? __( 'The billing system gave this estimate a customer link.', 'zorderz' )
				: __( 'The billing system gave this estimate no customer link.', 'zorderz' ),
		);
	}

	/**
	 * zest_test_estimate_link {number}: administrators only, never the shared device, never an account in Privacy
	 * View; one test customer's estimate by its number (the billing adapter refuses any other customer before anything
	 * that could change the estimate is asked). Nothing is stored.
	 */
	public static function ajax_test_estimate_link(): void {
		$uid = ZEST_Access::guard();
		if ( ! current_user_can( 'manage_options' ) || ZEST_Access::is_kiosk( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'zorderz' ) ), 403 );
		}
		if ( class_exists( 'ZEST_Privacy_View' ) ) {
			ZEST_Privacy_View::no_writes();
		}
		if ( ! class_exists( 'ZEST_Billing_Doc' ) || ! ZEST_Billing_Doc::available() ) {
			wp_send_json_error( array( 'message' => __( 'The billing system is not connected.', 'zorderz' ) ) );
		}
		$number = isset( $_POST['number'] ) ? (string) preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $_POST['number'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ZEST_Access::guard() above
		$reply  = self::link_test_reply( ZEST_Billing_Doc::probe_customer_link( $number ) );
		if ( empty( $reply['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) $reply['message'] ) );
		}
		unset( $reply['ok'] );
		wp_send_json_success( $reply );
	}

	public static function rows(): void {
		$route = (string) get_option( 'zest_ai_route', 'router' );
		$mode  = class_exists( 'ZEST_Terms_Note' ) ? ZEST_Terms_Note::mode() : 'test';
		$st    = class_exists( 'ZEST_Client_Index' ) ? ZEST_Client_Index::state() : array();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Estimates Ai road', 'zorderz' ); ?></th>
			<td>
				<select name="zest_ai_route">
					<option value="router" <?php selected( 'gateway' !== $route ); ?>><?php esc_html_e( 'The platform\'s Ai router (when it is set up)', 'zorderz' ); ?></option>
					<option value="gateway" <?php selected( 'gateway' === $route ); ?>><?php esc_html_e( 'The chat gateway', 'zorderz' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Where Estimates sends its Ai reads. The router codes customer details before they leave the site and keeps to the Ai budget.', 'zorderz' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Payment schedule in estimate notes', 'zorderz' ); ?></th>
			<td>
				<select name="zest_payment_notes_mode">
					<option value="off" <?php selected( 'off' === $mode ); ?>><?php esc_html_e( 'Off', 'zorderz' ); ?></option>
					<option value="test" <?php selected( 'test' === $mode ); ?>><?php esc_html_e( 'Test customers only', 'zorderz' ); ?></option>
					<option value="on" <?php selected( 'on' === $mode ); ?>><?php esc_html_e( 'On', 'zorderz' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Approve writes the deposit and final payment into the notes of estimates in a payment-schedule group.', 'zorderz' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Customer lookup index', 'zorderz' ); ?></th>
			<td><p class="description"><?php
				if ( ! empty( $st['complete'] ) ) {
					/* translators: %d: clients */
					echo esc_html( sprintf( __( 'Ready: %d clients from the billing system.', 'zorderz' ), (int) ( $st['total'] ?? 0 ) ) );
				} elseif ( (int) ( $st['pages'] ?? 0 ) > 0 ) {
					/* translators: 1: pages read, 2: pages */
					echo esc_html( sprintf( __( 'Building: page %1$d of %2$d. Lookups ask the billing system until it is ready.', 'zorderz' ), max( 0, (int) $st['page'] - 1 ), (int) $st['pages'] ) );
				} else {
					esc_html_e( 'Not built yet. It builds in the background once the billing system is connected.', 'zorderz' );
				}
				if ( '' !== (string) ( $st['last_error'] ?? '' ) ) {
					echo ' ' . esc_html( (string) $st['last_error'] );
				}
			?></p></td>
		</tr>
		<?php
		$fu_mode = class_exists( 'ZEST_Followup' ) ? ZEST_Followup::mode() : 'test';
		$pv_look = class_exists( 'ZEST_Privacy_View' ) && ZEST_Privacy_View::open();
		$mark    = class_exists( 'ZEST_Terms_Note' ) ? ZEST_Terms_Note::test_mark() : '';
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Follow-up line on billing estimates', 'zorderz' ); ?></th>
			<td>
				<select name="zest_followup_line_mode">
					<option value="off" <?php selected( 'off' === $fu_mode ); ?>><?php esc_html_e( 'Off', 'zorderz' ); ?></option>
					<option value="test" <?php selected( 'test' === $fu_mode ); ?>><?php esc_html_e( 'Test customers only', 'zorderz' ); ?></option>
					<option value="on" <?php selected( 'on' === $fu_mode ); ?>><?php esc_html_e( 'On', 'zorderz' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'When someone records what a customer said about an open estimate, one short fixed line (never what was typed) is added to the estimate\'s notes in the billing system, where the customer can read it. The follow-up itself is recorded either way.', 'zorderz' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Estimates in Privacy View', 'zorderz' ); ?></th>
			<td>
				<select name="zest_privacy_view_mode">
					<option value="off" <?php selected( ! $pv_look ); ?>><?php esc_html_e( 'Off', 'zorderz' ); ?></option>
					<option value="look" <?php selected( $pv_look ); ?>><?php esc_html_e( 'Look only', 'zorderz' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Whether an account in Privacy View may look at Estimates: the open list, past estimates and follow-ups, with customers, numbers and team members shown as codes, no prices and nothing anyone typed. It can never change anything.', 'zorderz' ); ?></p>
			</td>
		</tr>
		<?php if ( current_user_can( 'manage_options' ) && class_exists( 'ZEST_Billing_Doc' ) && ZEST_Billing_Doc::available() ) : ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Estimate customer link test', 'zorderz' ); ?></th>
			<td>
				<p class="description" style="max-width:700px;margin-top:0;"><?php
					/* translators: %s: the mark a test customer's name starts with */
					echo esc_html( sprintf( __( 'Does the billing system give an estimate a link a customer can open, as it does for an invoice? Type the number of one test customer\'s estimate (a customer whose name starts with "%s") and press Test. It tries the estimate\'s own link fields, then the billing system\'s share-link request, which may mark that estimate sent. Any other customer\'s estimate is refused. Nothing is saved.', 'zorderz' ), $mark ) );
				?></p>
				<input type="text" id="zest-link-test-number" inputmode="numeric" pattern="[0-9]*" autocomplete="off" aria-label="<?php esc_attr_e( 'Estimate number', 'zorderz' ); ?>" placeholder="#" style="width:120px;" />
				<button type="button" id="zest-link-test-go" class="button"><?php esc_html_e( 'Test estimate link', 'zorderz' ); ?></button>
				<div id="zest-link-test-out" role="status" aria-live="polite" style="margin-top:12px;max-width:700px;"></div>
				<script>
				( function () {
					var go = document.getElementById( 'zest-link-test-go' ), box = document.getElementById( 'zest-link-test-number' ), out = document.getElementById( 'zest-link-test-out' );
					if ( ! go || ! box || ! out ) { return; }
					var words = <?php echo wp_json_encode( array(
						'asking' => __( 'Asking the billing system', 'zorderz' ),
						'failed' => __( 'The test could not run.', 'zorderz' ),
						'net'    => __( 'Network error. Please try again.', 'zorderz' ),
						'est'    => __( 'Estimate #', 'zorderz' ),
						'try'    => __( 'Try', 'zorderz' ),
						'gave'   => __( 'The billing system gave', 'zorderz' ),
						/* translators: %s: which of the three tries */
						'link'   => __( 'Customer link from %s (select and copy it; open it in a private window to check what a customer sees):', 'zorderz' ),
					) ); ?>;
					function line( tag, text ) { var e = document.createElement( tag ); e.textContent = text; out.appendChild( e ); return e; }
					function post( action, data ) {
						var fd = new FormData();
						fd.append( 'action', action );
						fd.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( ZEST_NONCE ) ); ?> );
						Object.keys( data ).forEach( function ( k ) { fd.append( k, data[ k ] ); } );
						return fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) { return r.json(); } );
					}
					go.addEventListener( 'click', function () {
						go.disabled = true;
						out.textContent = words.asking + '\u2026';
						post( 'zest_test_estimate_link', { number: String( box.value || '' ).replace( /\D+/g, '' ) } ).then( function ( res ) {
							go.disabled = false;
							out.textContent = '';
							var d = res && res.data;
							if ( ! res || ! res.success ) { line( 'p', ( d && d.message ) || words.failed ); return; }
							line( 'p', words.est + d.number + ( d.status ? ' (' + d.status + ')' : '' ) );
							var t = document.createElement( 'table' ); t.className = 'widefat striped';
							var head = t.createTHead().insertRow();
							[ words[ 'try' ], words.gave ].forEach( function ( w ) { var th = document.createElement( 'th' ); th.textContent = w; head.appendChild( th ); } );
							var body = t.createTBody();
							( d.tries || [] ).forEach( function ( p ) {
								var row = body.insertRow();
								var code = document.createElement( 'code' ); code.textContent = p.label;
								row.insertCell().appendChild( code );
								row.insertCell().textContent = p.gave;
							} );
							out.appendChild( t );
							// A link found is shown as text to select, never as a link to follow.
							( d.tries || [] ).forEach( function ( p ) {
								if ( ! p.link ) { return; }
								line( 'p', words.link.replace( '%s', p.label ) );
								var f = document.createElement( 'input' ); f.type = 'text'; f.readOnly = true; f.value = p.link; f.style.width = '100%';
								out.appendChild( f );
							} );
							line( 'p', d.message ).style.fontWeight = '600';
						} ).catch( function () { go.disabled = false; out.textContent = words.net; } );
					} );
				}() );
				</script>
			</td>
		</tr>
		<?php endif; ?>
		<?php $lo = class_exists( 'ZEST_Line_Options' ) ? ZEST_Line_Options::status() : array( 'from' => 'none' ); ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Line choices', 'zorderz' ); ?></th>
			<td><p class="description"><?php
				$from = array(
					'hub'  => __( 'From the charts hub', 'zorderz' ),
					'pack' => __( 'From the Identity Pack', 'zorderz' ),
					'none' => __( 'None: the editors offer no press-and-hold choices until a pack or the charts hub supplies them.', 'zorderz' ),
				);
				echo esc_html( $from[ $lo['from'] ] ?? $from['none'] );
				if ( '' !== (string) ( $lo['built'] ?? '' ) ) {
					/* translators: %s: a date */
					echo ' ' . esc_html( sprintf( __( '(built %s)', 'zorderz' ), (string) $lo['built'] ) );
				}
				if ( '' !== (string) ( $lo['last_error'] ?? '' ) ) {
					echo ' ' . esc_html( sprintf( __( 'Last fetch: %s.', 'zorderz' ), (string) $lo['last_error'] ) );
				}
			?></p></td>
		</tr>
		<?php
	}
}
