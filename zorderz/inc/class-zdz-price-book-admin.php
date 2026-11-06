<?php
/**
 * Zorderz -> Price Book: the administrator's view of ZDZ_Price_Book.
 *
 * One page. What is live (version, the pack it came from, its families), and for each family the review an owner
 * needs: its price rules and business rules, every priced item with its price and pair price, and -- for an
 * administrator only -- its supplier cost and margin, the items that carry a cost but no customer price, and the
 * items to review (price below cost, thin margin). Below that, the one-use entitlements (a free service earned by a
 * purchase) with Void, so a declined document gives the customer the entitlement back.
 *
 * The book itself changes through the business's Identity Pack (Zorderz -> Identity Pack: preview, apply, revert) or
 * item by item in the Item Engine. manage_options to open; costs only for the admin rung (ZDZ_Price_Book::
 * may_see_costs); every action nonce-checked and logged.
 *
 * @package Zorderz
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Price_Book_Admin {

	const PAGE = 'zdz-price-book';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ), 21 );
		add_action( 'admin_post_zdz_pb_void_use', array( __CLASS__, 'handle_void_use' ) );
	}

	public static function add_page() {
		if ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on() ) {
			return;
		}
		add_submenu_page( 'zdz-core-settings', __( 'Price Book', 'zorderz' ), __( 'Price Book', 'zorderz' ), ZDZ_Price_Book::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	/* ------------------------------------------------------------------ actions */

	public static function handle_void_use() {
		if ( ! current_user_can( ZDZ_Price_Book::CAP ) || ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		check_admin_referer( 'zdz_pb_void_use' );
		$id  = absint( wp_unslash( $_POST['id'] ?? 0 ) );   // phpcs:ignore WordPress.Security.NonceVerification -- checked above
		$why = sanitize_text_field( wp_unslash( (string) ( $_POST['why'] ?? '' ) ) );   // phpcs:ignore WordPress.Security.NonceVerification
		$ok  = ZDZ_Price_Book::release_entitlement( $id, (int) get_current_user_id(), '' !== $why ? $why : 'voided by an administrator' );
		set_transient( 'zdz_pb_msg_' . (int) get_current_user_id(), array( $ok ? __( 'Voided. That purchase earns its entitlement again.', 'zorderz' ) : __( 'That use was already voided.', 'zorderz' ), $ok ), 300 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'zdz_pb_done' => $ok ? '1' : '0' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ screen */

	private static function money( $v ) {
		return null === $v ? '<span class="zdz-pb-muted">—</span>' : esc_html( number_format_i18n( (float) $v, ( floor( (float) $v ) == (float) $v ) ? 0 : 2 ) );
	}

	private static function user_label( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return '—';
		}
		$ini = (string) get_user_meta( $uid, 'zdz_user_initials', true );
		if ( '' !== $ini ) {
			return $ini;
		}
		$u = get_userdata( $uid );
		return $u ? (string) $u->display_name : '#' . $uid;
	}

	public static function render() {
		if ( ! current_user_can( ZDZ_Price_Book::CAP ) || ( class_exists( 'ZDZ_Privacy_View' ) && ZDZ_Privacy_View::is_on() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		ZDZ_Price_Book::ensure_tables();
		$uid   = (int) get_current_user_id();
		$costs = ZDZ_Price_Book::may_see_costs( $uid );

		echo '<div class="wrap zdz-pb"><h1>' . esc_html__( 'Price Book', 'zorderz' ) . '</h1>';
		echo '<style>.zdz-pb .zdz-pb-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 16px;margin:12px 0}.zdz-pb table{border-collapse:collapse;width:100%}.zdz-pb th,.zdz-pb td{text-align:left;padding:4px 8px;border-bottom:1px solid #eee;vertical-align:top}.zdz-pb td.n,.zdz-pb th.n{text-align:right;font-variant-numeric:tabular-nums}.zdz-pb-muted{color:#757575}.zdz-pb-warn{color:#8a4b00}.zdz-pb-scroll{max-height:520px;overflow:auto}</style>';

		$msg = get_transient( 'zdz_pb_msg_' . $uid );
		if ( is_array( $msg ) && isset( $_GET['zdz_pb_done'] ) ) {   // phpcs:ignore WordPress.Security.NonceVerification -- display only
			delete_transient( 'zdz_pb_msg_' . $uid );
			echo '<div class="notice ' . ( $msg[1] ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( (string) $msg[0] ) . '</p></div>';
		}

		$pub = ZDZ_Price_Book::published();
		$ver = get_option( ZDZ_Price_Book::VERSION_OPT, array() );
		echo '<div class="zdz-pb-card"><h2 style="margin-top:0">' . esc_html__( 'Live now', 'zorderz' ) . '</h2>';
		if ( $pub ) {
			echo '<p><strong>' . esc_html( $pub['version'] ) . '</strong>';
			if ( is_array( $ver ) && ! empty( $ver['pack'] ) ) {
				/* translators: 1: pack name, 2: date */
				echo ' · ' . esc_html( sprintf( __( 'from the pack %1$s, %2$s', 'zorderz' ), (string) $ver['pack'], substr( (string) ( $ver['applied_at'] ?? '' ), 0, 10 ) ) );
			}
			echo ' · ' . esc_html( sprintf( /* translators: %d: items */ _n( '%d item', '%d items', count( $pub['items'] ), 'zorderz' ), count( $pub['items'] ) ) ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html__( 'There is no price book.', 'zorderz' ) . '</strong> ' . esc_html__( 'The apps keep their own pricing until one is added.', 'zorderz' ) . '</p>';
		}
		echo '<p class="zdz-pb-muted">' . esc_html__( 'The price book comes from your Identity Pack (catalog, pricing and costs files): preview it, apply it and revert it on the Identity Pack screen. Single items can be changed in the Item Engine.', 'zorderz' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=zdz-identity-pack' ) ) . '">' . esc_html__( 'Identity Pack', 'zorderz' ) . '</a> · <a href="' . esc_url( admin_url( 'admin.php?page=zdz-item-engine' ) ) . '">' . esc_html__( 'Item Engine', 'zorderz' ) . '</a></p>';
		echo '</div>';

		foreach ( $pub ? $pub['families'] : array() as $key => $fam ) {
			self::render_family( $key, $fam, $costs );
		}

		$uses = ZDZ_Price_Book::entitlement_uses( 50 );
		echo '<div class="zdz-pb-card"><h2 style="margin-top:0">' . esc_html__( 'One-use entitlements', 'zorderz' ) . '</h2>';
		if ( ! $uses ) {
			echo '<p class="zdz-pb-muted">' . esc_html__( 'None yet. When a document receives an entitlement it is listed here, with the purchase that earned it. Void one when that document is declined or replaced, so the customer keeps the entitlement.', 'zorderz' ) . '</p>';
		} else {
			echo '<table><thead><tr><th>' . esc_html__( 'When', 'zorderz' ) . '</th><th>' . esc_html__( 'Rule', 'zorderz' ) . '</th><th>' . esc_html__( 'Billing client', 'zorderz' ) . '</th><th>' . esc_html__( 'Earned by', 'zorderz' ) . '</th><th>' . esc_html__( 'Used on', 'zorderz' ) . '</th><th>' . esc_html__( 'By', 'zorderz' ) . '</th><th>' . esc_html__( 'Status', 'zorderz' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $uses as $u ) {
				$live = 1 === (int) ( $u['live'] ?? 0 );
				echo '<tr><td>' . esc_html( substr( (string) $u['used_at'], 0, 16 ) ) . '</td><td>' . esc_html( (string) $u['rule_id'] ) . '</td><td>#' . esc_html( (string) $u['client_id'] ) . '</td><td>#' . esc_html( (string) $u['qualifying_doc'] ) . ' ' . esc_html( (string) $u['qualifying_date'] ) . '</td>';
				echo '<td>' . esc_html( '' !== (string) $u['used_doc'] ? (string) $u['used_doc'] : __( '(reserved)', 'zorderz' ) ) . '</td><td>' . esc_html( self::user_label( $u['used_by'] ) ) . '</td>';
				echo '<td>' . ( $live ? esc_html__( 'in use', 'zorderz' ) : esc_html__( 'voided', 'zorderz' ) . ' ' . esc_html( substr( (string) $u['voided_at'], 0, 16 ) ) ) . '</td><td>';
				if ( $live ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
					wp_nonce_field( 'zdz_pb_void_use' );
					echo '<input type="hidden" name="action" value="zdz_pb_void_use"><input type="hidden" name="id" value="' . (int) $u['id'] . '"><button class="button">' . esc_html__( 'Void', 'zorderz' ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></div>';
	}

	private static function render_family( $key, array $fam, $costs ) {
		echo '<div class="zdz-pb-card"><h2 style="margin-top:0">' . esc_html( (string) $fam['label'] ) . ' <span class="zdz-pb-muted">(' . esc_html( (string) $key ) . ')</span></h2>';
		if ( $fam['price_rules'] || $fam['rules'] ) {
			echo '<ul>';
			foreach ( (array) $fam['price_rules'] as $what => $price ) {
				echo '<li><strong>' . esc_html( (string) $what ) . ':</strong> ' . esc_html( (string) $price ) . '</li>';
			}
			foreach ( (array) $fam['rules'] as $r ) {
				echo '<li><strong>' . esc_html__( 'Rule:', 'zorderz' ) . '</strong> ' . esc_html( (string) ( $r['text'] ?? '' ) ) . '</li>';
			}
			echo '</ul>';
		}
		$priced    = array();
		$cost_only = array();
		$warn      = array();
		$thin      = ZDZ_Price_Book::thin_margin();
		foreach ( ZDZ_Price_Book::items( $key ) as $sku => $it ) {
			$c = $costs ? ZDZ_Price_Book::cost( $sku ) : null;
			if ( null === ( $it['price'] ?? null ) ) {
				$cost_only[ $sku ] = array( $it, $c );
				continue;
			}
			$priced[ $sku ] = array( $it, $c );
			if ( null !== $c && (float) $it['price'] > 0 ) {
				if ( (float) $it['price'] < $c ) {
					$warn[] = $sku . ': ' . __( 'price below cost', 'zorderz' );
				} elseif ( ( (float) $it['price'] - $c ) / (float) $it['price'] < $thin ) {
					$warn[] = $sku . ': ' . __( 'thin margin', 'zorderz' );
				}
			}
		}
		if ( $warn ) {
			echo '<p class="zdz-pb-warn"><strong>' . esc_html( sprintf( /* translators: %d: count */ _n( '%d to review:', '%d to review:', count( $warn ), 'zorderz' ), count( $warn ) ) ) . '</strong> ' . esc_html( implode( '; ', array_slice( $warn, 0, 20 ) ) ) . ( count( $warn ) > 20 ? ' …' : '' ) . '</p>';
		}
		/* translators: %d: count */
		echo '<h3>' . esc_html( sprintf( __( 'Priced items (%d)', 'zorderz' ), count( $priced ) ) ) . '</h3><div class="zdz-pb-scroll"><table><thead><tr><th>SKU</th><th>' . esc_html__( 'Item', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Price', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Pair', 'zorderz' ) . '</th>';
		if ( $costs ) {
			echo '<th class="n">' . esc_html__( 'Cost', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Margin', 'zorderz' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Basis', 'zorderz' ) . '</th></tr></thead><tbody>';
		foreach ( $priced as $sku => $row ) {
			list( $it, $c ) = $row;
			echo '<tr><td>' . esc_html( (string) $sku ) . '</td><td>' . esc_html( (string) ( $it['label'] ?? '' ) ) . ( ! empty( $it['warning'] ) ? '<br><span class="zdz-pb-warn">' . esc_html( (string) $it['warning'] ) . '</span>' : '' ) . '</td>';
			echo '<td class="n">' . self::money( $it['price'] ) . '</td><td class="n">' . self::money( $it['double_price'] ?? null ) . '</td>';   // phpcs:ignore -- money() escapes
			if ( $costs ) {
				$m = ( null !== $c && (float) $it['price'] > 0 ) ? round( 100 * ( (float) $it['price'] - $c ) / (float) $it['price'] ) . '%' : '—';
				echo '<td class="n">' . self::money( $c ) . '</td><td class="n">' . esc_html( $m ) . '</td>';   // phpcs:ignore -- money() escapes
			}
			echo '<td>' . esc_html( (string) ( $it['price_basis'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( $costs && $cost_only ) {
			/* translators: %d: count */
			echo '<details><summary>' . esc_html( sprintf( __( 'Items with a cost and no customer price (%d)', 'zorderz' ), count( $cost_only ) ) ) . '</summary><div class="zdz-pb-scroll"><table><thead><tr><th>SKU</th><th>' . esc_html__( 'Item', 'zorderz' ) . '</th><th class="n">' . esc_html__( 'Cost', 'zorderz' ) . '</th><th>' . esc_html__( 'Unit', 'zorderz' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $cost_only, 0, 2000, true ) as $sku => $row ) {
				list( $it, $c ) = $row;
				echo '<tr><td>' . esc_html( (string) $sku ) . '</td><td>' . esc_html( (string) ( $it['label'] ?? '' ) ) . '</td><td class="n">' . self::money( $c ) . '</td><td>' . esc_html( (string) ( $it['uom'] ?? '' ) ) . '</td></tr>';   // phpcs:ignore -- money() escapes
			}
			echo '</tbody></table></div></details>';
		} elseif ( $cost_only ) {
			/* translators: %d: count */
			echo '<p class="zdz-pb-muted">' . esc_html( sprintf( __( '%d items have no customer price (quote by hand).', 'zorderz' ), count( $cost_only ) ) ) . '</p>';
		}
		echo '</div>';
	}
}
