<?php
/**
 * Zorderz — Item Engine admin screen
 *
 * One screen under the Zorderz menu where a business defines its catalog:
 * Items (product/service), their user-named subtypes, aliases, and the reusable
 * Pricing Schemes. It also surfaces the COUNTS CONTRACT status — the catalog-driven
 * vocabulary every module now speaks — so an owner can see exactly which of their
 * Items are counted across the apps.
 *
 * Nothing here seeds anything. The catalog starts empty and stays empty until an
 * owner adds Items by hand, applies the clearly-marked sample set (with a typed
 * confirmation), or approves a discovery proposal item by item.
 *
 * The style follows the Business Profile admin: a PARENT under the Zorderz menu,
 * a nonce-guarded POST handler with an action switch, and self-rendered tables.
 *
 * @package Zorderz
 * @since   1.1.0
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Item_Engine_Admin {

	const PARENT = 'zdz-core-settings';
	const SLUG   = 'zdz-item-engine';

	/** Notices to render on the next page load of this request. */
	private static $notices = [];

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_pages' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_post' ] );
	}

	public static function add_pages() {
		add_submenu_page(
			self::PARENT,
			__( 'Item Engine', 'zorderz' ),
			__( 'Item Engine', 'zorderz' ),
			'manage_options',
			self::SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	// ───────────────────────────────────────────────────────── POST

	public static function handle_post() {
		if ( ! isset( $_POST['zdz_ie_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the catalog.', 'zorderz' ) );
		}
		$action = sanitize_key( wp_unslash( $_POST['zdz_ie_action'] ) );
		check_admin_referer( 'zdz_ie_' . $action );

		switch ( $action ) {
			case 'save_item':
				self::save_item();
				break;
			case 'delete_item':
				$id = isset( $_POST['item_id'] ) ? ZDZ_Item_Engine::slug( wp_unslash( $_POST['item_id'] ) ) : '';
				self::notice( ZDZ_Item_Engine::delete_item( $id ) ? __( 'Item deleted.', 'zorderz' ) : __( 'Item not found.', 'zorderz' ), 'success' );
				break;
			case 'save_scheme':
				self::save_scheme();
				break;
			case 'delete_scheme':
				$id = isset( $_POST['scheme_id'] ) ? ZDZ_Item_Engine::slug( wp_unslash( $_POST['scheme_id'] ) ) : '';
				self::notice( ZDZ_Item_Engine::delete_scheme( $id ) ? __( 'Pricing scheme deleted.', 'zorderz' ) : __( 'Scheme not found.', 'zorderz' ), 'success' );
				break;
			case 'clone_scheme':
				self::clone_scheme();
				break;
			case 'save_subtype':
				self::save_subtype();
				break;
			case 'delete_subtype':
				$slug = isset( $_POST['subtype'] ) ? ZDZ_Item_Engine::slug( wp_unslash( $_POST['subtype'] ) ) : '';
				self::notice( ZDZ_Item_Engine::delete_subtype( $slug ) ? __( 'Subtype deleted.', 'zorderz' ) : __( 'Subtype not found.', 'zorderz' ), 'success' );
				break;
			case 'apply_sample':
				self::apply_sample();
				break;
			case 'clear_catalog':
				self::clear_catalog();
				break;
		}

		// Re-render on this same screen (PRG would drop our notices without a store).
		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'zdz_ie_done' => $action ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function save_item() {
		$in  = isset( $_POST['item'] ) && is_array( $_POST['item'] ) ? wp_unslash( $_POST['item'] ) : [];
		$item = [
			'id'                 => $in['id'] ?? '',
			'type'               => in_array( $in['type'] ?? '', ZDZ_Item_Engine::TYPES, true ) ? $in['type'] : 'product',
			'subtype'            => $in['subtype'] ?? '',
			'subtype_label'      => $in['subtype_label'] ?? '',
			'subtype_scope'      => in_array( $in['subtype_scope'] ?? '', ZDZ_Item_Engine::SCOPES, true ) ? $in['subtype_scope'] : 'global',
			'display_name'       => $in['display_name'] ?? '',
			'aliases'            => $in['aliases'] ?? '',
			'negative_aliases'   => $in['negative_aliases'] ?? '',
			'sellable'           => $in['sellable'] ?? 'standalone',
			'unit_noun_singular' => $in['unit_noun_singular'] ?? '',
			'unit_noun_plural'   => $in['unit_noun_plural'] ?? '',
			'parent_item_id'     => $in['parent_item_id'] ?? '',
			'pricing_scheme_id'  => $in['pricing_scheme_id'] ?? '',
			'match_priority'     => (int) ( $in['match_priority'] ?? 50 ),
			'countable'          => ! empty( $in['countable'] ),
			'active'             => ! empty( $in['active'] ),
			'attributes'         => self::decode_json_field( $in['attributes'] ?? '' ),
			'consumes'           => self::decode_json_field( $in['consumes'] ?? '' ),
			'external_refs'      => self::decode_json_field( $in['external_refs'] ?? '' ),
			'rules'              => self::lines_to_list( $in['rules'] ?? '' ),
		];
		$r = ZDZ_Item_Engine::save_item( $item );
		self::notice(
			is_wp_error( $r ) ? $r->get_error_message() : __( 'Item saved.', 'zorderz' ),
			is_wp_error( $r ) ? 'error' : 'success'
		);
	}

	private static function save_scheme() {
		$in     = isset( $_POST['scheme'] ) && is_array( $_POST['scheme'] ) ? wp_unslash( $_POST['scheme'] ) : [];
		$scheme = [
			'id'         => $in['id'] ?? '',
			'name'       => $in['name'] ?? '',
			'method'     => $in['method'] ?? 'flat',
			'scope'      => $in['scope'] ?? 'global',
			'currency'   => $in['currency'] ?? '',
			'expression' => $in['expression'] ?? '',
			'params'     => self::decode_json_field( $in['params'] ?? '' ),
		];
		$r = ZDZ_Item_Engine::save_scheme( $scheme );
		self::notice(
			is_wp_error( $r ) ? $r->get_error_message() : __( 'Pricing scheme saved.', 'zorderz' ),
			is_wp_error( $r ) ? 'error' : 'success'
		);
	}

	private static function clone_scheme() {
		$src  = isset( $_POST['scheme_id'] ) ? ZDZ_Item_Engine::slug( wp_unslash( $_POST['scheme_id'] ) ) : '';
		$name = isset( $_POST['new_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_name'] ) ) : '';
		$nid  = isset( $_POST['new_id'] ) ? sanitize_text_field( wp_unslash( $_POST['new_id'] ) ) : '';
		$r    = ZDZ_Item_Engine::clone_scheme( $src, $nid, $name );
		self::notice(
			is_wp_error( $r ) ? $r->get_error_message() : sprintf( /* translators: %s: new scheme id. */ __( 'Cloned to %s.', 'zorderz' ), $r ),
			is_wp_error( $r ) ? 'error' : 'success'
		);
	}

	private static function save_subtype() {
		$in = isset( $_POST['subtype'] ) && is_array( $_POST['subtype'] ) ? wp_unslash( $_POST['subtype'] ) : [];
		$r  = ZDZ_Item_Engine::ensure_subtype(
			$in['slug'] ?? '',
			$in['label'] ?? '',
			in_array( $in['scope'] ?? '', ZDZ_Item_Engine::SCOPES, true ) ? $in['scope'] : 'global',
			in_array( $in['type'] ?? '', ZDZ_Item_Engine::TYPES, true ) ? $in['type'] : 'product',
			(int) ( $in['match_priority'] ?? 50 )
		);
		self::notice(
			is_wp_error( $r ) ? $r->get_error_message() : __( 'Subtype saved.', 'zorderz' ),
			is_wp_error( $r ) ? 'error' : 'success'
		);
	}

	private static function apply_sample() {
		$typed = isset( $_POST['confirm'] ) ? trim( (string) wp_unslash( $_POST['confirm'] ) ) : '';
		if ( 0 !== strcasecmp( $typed, 'SAMPLE' ) ) {
			self::notice(
				sprintf( /* translators: %s: confirmation word. */ __( 'Nothing was applied. Type %s to load the sample set.', 'zorderz' ), '<code>SAMPLE</code>' ),
				'warning'
			);
			return;
		}
		$r = ZDZ_Item_Engine::apply_sample_pack( true );
		if ( is_wp_error( $r ) ) {
			self::notice( $r->get_error_message(), 'error' );
			return;
		}
		self::notice(
			sprintf(
				/* translators: 1: items, 2: schemes, 3: subtypes. */
				__( 'Sample set loaded: %1$d items, %2$d pricing schemes, %3$d subtypes. This is fictional demo data — clear it before going live.', 'zorderz' ),
				$r['items'],
				$r['schemes'],
				$r['subtypes']
			),
			'success'
		);
	}

	private static function clear_catalog() {
		$typed = isset( $_POST['confirm'] ) ? trim( (string) wp_unslash( $_POST['confirm'] ) ) : '';
		if ( 0 !== strcasecmp( $typed, 'CLEAR' ) ) {
			self::notice(
				sprintf( /* translators: %s: confirmation word. */ __( 'Nothing was cleared. Type %s to empty the catalog.', 'zorderz' ), '<code>CLEAR</code>' ),
				'warning'
			);
			return;
		}
		$items = 0;
		foreach ( array_keys( ZDZ_Item_Engine::all( [ 'active' => true ] ) + ZDZ_Item_Engine::all( [ 'active' => false ] ) ) as $id ) {
			if ( ZDZ_Item_Engine::delete_item( $id ) ) {
				$items++;
			}
		}
		$schemes = 0;
		foreach ( array_keys( ZDZ_Item_Engine::pricing_schemes() ) as $sid ) {
			if ( ZDZ_Item_Engine::delete_scheme( $sid ) ) {
				$schemes++;
			}
		}
		$subs = 0;
		foreach ( ZDZ_Item_Engine::subtypes() as $s ) {
			if ( ZDZ_Item_Engine::delete_subtype( $s['slug'] ) ) {
				$subs++;
			}
		}
		self::notice(
			sprintf(
				/* translators: 1: items, 2: schemes, 3: subtypes. */
				__( 'Catalog cleared: %1$d items, %2$d schemes, %3$d subtypes removed.', 'zorderz' ),
				$items,
				$schemes,
				$subs
			),
			'success'
		);
	}

	// ───────────────────────────────────────────────────────── render

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::absorb_redirect_notice();
		$empty = ZDZ_Item_Engine::is_empty();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Item Engine', 'zorderz' ); ?></h1>
			<p style="max-width:52em">
				<?php esc_html_e( 'Everything this business sells is an Item — a product (tangible) or a service (intangible). Underneath, you name your own subtypes. Prices are reusable Pricing Schemes. The count categories every app speaks come from this catalog, so there is no fixed product list baked into the code.', 'zorderz' ); ?>
			</p>
			<?php self::render_notices(); ?>

			<?php if ( $empty ) : ?>
				<div class="notice notice-info inline"><p>
					<?php esc_html_e( 'The catalog is empty — the blank-slate default. Add Items below, load the sample set to explore the mechanics, or connect a system and let discovery propose a catalog you approve item by item.', 'zorderz' ); ?>
				</p></div>
			<?php endif; ?>

			<?php
			self::render_counts_status();
			self::render_subtypes();
			self::render_schemes();
			self::render_items();
			self::render_discovery();
			self::render_sample_and_danger();
			?>
		</div>
		<?php
	}

	private static function render_counts_status() {
		$cats  = ZDZ_Item_Engine::count_categories();
		?>
		<h2><?php esc_html_e( 'Counts contract', 'zorderz' ); ?></h2>
		<p class="description" style="max-width:52em">
			<?php
			printf(
				/* translators: %s: shape token. */
				esc_html__( 'The cross-app count vocabulary is catalog-driven (shape %s). Any Item marked "counted" becomes a count category every module can tally, group and phrase using the Item\'s own unit nouns. An empty catalog means every consumer falls back to its own neutral default.', 'zorderz' ),
				'<code>' . esc_html( ZDZ_Item_Engine::COUNTS_SHAPE ) . '</code>'
			);
			?>
		</p>
		<table class="widefat striped" style="max-width:60em">
			<thead><tr>
				<th><?php esc_html_e( 'Count category (kind)', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Type', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Subtype', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Unit noun', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Parent', 'zorderz' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $cats ) : ?>
				<tr><td colspan="5"><em><?php esc_html_e( 'No count categories yet. Mark an Item as "counted" to add one.', 'zorderz' ); ?></em></td></tr>
			<?php else : ?>
				<?php foreach ( $cats as $id => $meta ) : ?>
					<tr>
						<td><code><?php echo esc_html( $id ); ?></code><br><?php echo esc_html( $meta['display_name'] ); ?></td>
						<td><?php echo esc_html( $meta['type'] ); ?></td>
						<td><?php echo esc_html( $meta['subtype'] ?: '—' ); ?></td>
						<td><?php echo esc_html( trim( $meta['unit_noun_singular'] . ' / ' . $meta['unit_noun_plural'], ' /' ) ?: '—' ); ?></td>
						<td><?php echo esc_html( $meta['parent_item_id'] ?: '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_subtypes() {
		$subs = ZDZ_Item_Engine::subtypes();
		?>
		<hr>
		<h2><?php esc_html_e( 'Subtypes', 'zorderz' ); ?></h2>
		<p class="description" style="max-width:52em"><?php esc_html_e( 'Your own labels under the two fixed types. A "global" subtype is offered when you create future Items; a "one-off" is used once and not offered again. Subtypes are ordinary taxonomy terms.', 'zorderz' ); ?></p>
		<table class="widefat striped" style="max-width:60em">
			<thead><tr>
				<th><?php esc_html_e( 'Subtype', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Type', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Scope', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Priority', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Items', 'zorderz' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $subs ) : ?>
				<tr><td colspan="6"><em><?php esc_html_e( 'No subtypes yet.', 'zorderz' ); ?></em></td></tr>
			<?php else : ?>
				<?php foreach ( $subs as $s ) : ?>
					<tr>
						<td><code><?php echo esc_html( $s['slug'] ); ?></code> — <?php echo esc_html( $s['label'] ); ?></td>
						<td><?php echo esc_html( $s['type'] ); ?></td>
						<td><?php echo esc_html( $s['scope'] ); ?></td>
						<td><?php echo esc_html( (string) $s['match_priority'] ); ?></td>
						<td><?php echo esc_html( (string) $s['count'] ); ?></td>
						<td>
							<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this subtype?', 'zorderz' ) ); ?>');" style="display:inline">
								<?php wp_nonce_field( 'zdz_ie_delete_subtype' ); ?>
								<input type="hidden" name="zdz_ie_action" value="delete_subtype">
								<input type="hidden" name="subtype" value="<?php echo esc_attr( $s['slug'] ); ?>">
								<button class="button-link delete"><?php esc_html_e( 'Delete', 'zorderz' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<h3><?php esc_html_e( 'Add / update a subtype', 'zorderz' ); ?></h3>
		<form method="post">
			<?php wp_nonce_field( 'zdz_ie_save_subtype' ); ?>
			<input type="hidden" name="zdz_ie_action" value="save_subtype">
			<table class="form-table" role="presentation"><tbody>
				<tr><th><?php esc_html_e( 'Name / slug', 'zorderz' ); ?></th><td><input type="text" name="subtype[slug]" class="regular-text" required></td></tr>
				<tr><th><?php esc_html_e( 'Label', 'zorderz' ); ?></th><td><input type="text" name="subtype[label]" class="regular-text" placeholder="<?php esc_attr_e( 'defaults from the name', 'zorderz' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Type', 'zorderz' ); ?></th><td><?php self::type_select( 'subtype[type]', 'product' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Scope', 'zorderz' ); ?></th><td><?php self::scope_select( 'subtype[scope]', 'global' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Match priority', 'zorderz' ); ?></th><td><input type="number" name="subtype[match_priority]" value="50" class="small-text"> <span class="description"><?php esc_html_e( 'higher wins on ambiguous text', 'zorderz' ); ?></span></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Save subtype', 'zorderz' ), 'secondary' ); ?>
		</form>
		<?php
	}

	private static function render_schemes() {
		$schemes = ZDZ_Item_Engine::pricing_schemes();
		$edit    = self::edit_target( 'edit_scheme' );
		$current = $edit ? ZDZ_Item_Engine::pricing_scheme( $edit ) : null;
		?>
		<hr>
		<h2><?php esc_html_e( 'Pricing schemes', 'zorderz' ); ?></h2>
		<p class="description" style="max-width:52em"><?php esc_html_e( 'How you price things — reusable and cloneable. Build one rate, clone it, adjust. Methods: flat, per unit, per hour, per area, per visit, tiered, formula (e.g. cost x markup), and quote-only.', 'zorderz' ); ?></p>
		<table class="widefat striped" style="max-width:70em">
			<thead><tr>
				<th><?php esc_html_e( 'Scheme', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Method', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Params / expression', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Scope', 'zorderz' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $schemes ) : ?>
				<tr><td colspan="5"><em><?php esc_html_e( 'No pricing schemes yet.', 'zorderz' ); ?></em></td></tr>
			<?php else : ?>
				<?php foreach ( $schemes as $s ) : ?>
					<tr>
						<td><code><?php echo esc_html( $s['id'] ); ?></code><br><?php echo esc_html( $s['name'] ); ?><?php echo $s['cloned_from'] ? '<br><span class="description">' . esc_html( sprintf( /* translators: %s: source id */ __( 'cloned from %s', 'zorderz' ), $s['cloned_from'] ) ) . '</span>' : ''; ?></td>
						<td><?php echo esc_html( $s['method'] ); ?></td>
						<td><code style="font-size:11px"><?php echo esc_html( 'formula' === $s['method'] ? $s['expression'] : wp_json_encode( $s['params'] ) ); ?></code></td>
						<td><?php echo esc_html( $s['scope'] ); ?></td>
						<td>
							<a class="button-link" href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'edit_scheme' => $s['id'] ] ) ); ?>#scheme-form"><?php esc_html_e( 'Edit', 'zorderz' ); ?></a>
							<form method="post" style="display:inline">
								<?php wp_nonce_field( 'zdz_ie_clone_scheme' ); ?>
								<input type="hidden" name="zdz_ie_action" value="clone_scheme">
								<input type="hidden" name="scheme_id" value="<?php echo esc_attr( $s['id'] ); ?>">
								<button class="button-link"><?php esc_html_e( 'Clone', 'zorderz' ); ?></button>
							</form>
							<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this scheme?', 'zorderz' ) ); ?>');" style="display:inline">
								<?php wp_nonce_field( 'zdz_ie_delete_scheme' ); ?>
								<input type="hidden" name="zdz_ie_action" value="delete_scheme">
								<input type="hidden" name="scheme_id" value="<?php echo esc_attr( $s['id'] ); ?>">
								<button class="button-link delete"><?php esc_html_e( 'Delete', 'zorderz' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h3 id="scheme-form"><?php echo $current ? esc_html__( 'Edit pricing scheme', 'zorderz' ) : esc_html__( 'Add a pricing scheme', 'zorderz' ); ?></h3>
		<form method="post">
			<?php wp_nonce_field( 'zdz_ie_save_scheme' ); ?>
			<input type="hidden" name="zdz_ie_action" value="save_scheme">
			<table class="form-table" role="presentation"><tbody>
				<tr><th><?php esc_html_e( 'Id', 'zorderz' ); ?></th><td><input type="text" name="scheme[id]" class="regular-text" value="<?php echo esc_attr( $current['id'] ?? '' ); ?>" <?php echo $current ? 'readonly' : ''; ?>></td></tr>
				<tr><th><?php esc_html_e( 'Name', 'zorderz' ); ?></th><td><input type="text" name="scheme[name]" class="regular-text" value="<?php echo esc_attr( $current['name'] ?? '' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Method', 'zorderz' ); ?></th><td>
					<select name="scheme[method]">
						<?php foreach ( ZDZ_Item_Engine::PRICING_METHODS as $m ) : ?>
							<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $current['method'] ?? 'flat', $m ); ?>><?php echo esc_html( $m ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Params (JSON)', 'zorderz' ); ?></th><td>
					<textarea name="scheme[params]" rows="3" class="large-text code" placeholder='{"rate":25,"min":25}'><?php echo esc_textarea( isset( $current['params'] ) ? wp_json_encode( $current['params'] ) : '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'e.g. flat: {"amount":100} · per_unit: {"rate":25} · per_area: {"rate":3,"area_basis":"sq_ft"} · tiered: {"axis":"qty","tiers":[{"up_to":5,"amount":30},{"up_to":null,"amount":20}]}', 'zorderz' ); ?></p>
				</td></tr>
				<tr><th><?php esc_html_e( 'Expression (formula only)', 'zorderz' ); ?></th><td><input type="text" name="scheme[expression]" class="large-text code" value="<?php echo esc_attr( $current['expression'] ?? '' ); ?>" placeholder="cost * markup"></td></tr>
				<tr><th><?php esc_html_e( 'Scope', 'zorderz' ); ?></th><td>
					<select name="scheme[scope]">
						<option value="global" <?php selected( $current['scope'] ?? 'global', 'global' ); ?>><?php esc_html_e( 'global (reusable)', 'zorderz' ); ?></option>
						<option value="item" <?php selected( $current['scope'] ?? '', 'item' ); ?>><?php esc_html_e( 'item-specific', 'zorderz' ); ?></option>
					</select>
				</td></tr>
			</tbody></table>
			<?php submit_button( $current ? __( 'Update scheme', 'zorderz' ) : __( 'Add scheme', 'zorderz' ), 'secondary' ); ?>
		</form>
		<?php
	}

	private static function render_items() {
		$items = ZDZ_Item_Engine::all( [ 'active' => true ] ) + ZDZ_Item_Engine::all( [ 'active' => false ] );
		$edit  = self::edit_target( 'edit_item' );
		$cur   = $edit ? ZDZ_Item_Engine::get( $edit ) : null;
		?>
		<hr>
		<h2><?php esc_html_e( 'Items', 'zorderz' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Item', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Type', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Subtype', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Aliases', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Counted', 'zorderz' ); ?></th>
				<th><?php esc_html_e( 'Pricing', 'zorderz' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $items ) : ?>
				<tr><td colspan="7"><em><?php esc_html_e( 'No items yet.', 'zorderz' ); ?></em></td></tr>
			<?php else : ?>
				<?php foreach ( $items as $it ) : ?>
					<tr<?php echo $it['active'] ? '' : ' style="opacity:.55"'; ?>>
						<td><code><?php echo esc_html( $it['id'] ); ?></code><br><?php echo esc_html( $it['display_name'] ); ?><?php echo $it['active'] ? '' : ' <em>(' . esc_html__( 'inactive', 'zorderz' ) . ')</em>'; ?></td>
						<td><?php echo esc_html( $it['type'] ); ?></td>
						<td><?php echo esc_html( $it['subtype'] ?: '—' ); ?></td>
						<td><span class="description"><?php echo esc_html( implode( ', ', array_map( fn( $a ) => is_array( $a ) ? ( $a['value'] ?? '' ) : $a, array_slice( $it['aliases'], 0, 6 ) ) ) ); ?></span></td>
						<td><?php echo $it['countable'] ? '✓' : '—'; ?></td>
						<td><?php echo esc_html( $it['pricing_scheme_id'] ?: '—' ); ?></td>
						<td>
							<a class="button-link" href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'edit_item' => $it['id'] ] ) ); ?>#item-form"><?php esc_html_e( 'Edit', 'zorderz' ); ?></a>
							<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this item?', 'zorderz' ) ); ?>');" style="display:inline">
								<?php wp_nonce_field( 'zdz_ie_delete_item' ); ?>
								<input type="hidden" name="zdz_ie_action" value="delete_item">
								<input type="hidden" name="item_id" value="<?php echo esc_attr( $it['id'] ); ?>">
								<button class="button-link delete"><?php esc_html_e( 'Delete', 'zorderz' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h3 id="item-form"><?php echo $cur ? esc_html__( 'Edit item', 'zorderz' ) : esc_html__( 'Add an item', 'zorderz' ); ?></h3>
		<form method="post">
			<?php wp_nonce_field( 'zdz_ie_save_item' ); ?>
			<input type="hidden" name="zdz_ie_action" value="save_item">
			<table class="form-table" role="presentation"><tbody>
				<tr><th><?php esc_html_e( 'Id', 'zorderz' ); ?></th><td><input type="text" name="item[id]" class="regular-text" value="<?php echo esc_attr( $cur['id'] ?? '' ); ?>" <?php echo $cur ? 'readonly' : ''; ?> placeholder="<?php esc_attr_e( 'defaults from the name', 'zorderz' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Display name', 'zorderz' ); ?></th><td><input type="text" name="item[display_name]" class="regular-text" value="<?php echo esc_attr( $cur['display_name'] ?? '' ); ?>" required></td></tr>
				<tr><th><?php esc_html_e( 'Type', 'zorderz' ); ?></th><td><?php self::type_select( 'item[type]', $cur['type'] ?? 'product' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Subtype', 'zorderz' ); ?></th><td>
					<input list="zdz-subtype-list" type="text" name="item[subtype]" class="regular-text" value="<?php echo esc_attr( $cur['subtype'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'your own label — new ones are created', 'zorderz' ); ?>">
					<datalist id="zdz-subtype-list"><?php foreach ( ZDZ_Item_Engine::subtypes() as $s ) : ?><option value="<?php echo esc_attr( $s['slug'] ); ?>"><?php echo esc_html( $s['label'] ); ?></option><?php endforeach; ?></datalist>
					<span class="description"><?php esc_html_e( 'scope for a new subtype:', 'zorderz' ); ?></span> <?php self::scope_select( 'item[subtype_scope]', $cur ? $cur['subtype_scope'] : 'global' ); ?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Aliases', 'zorderz' ); ?></th><td>
					<textarea name="item[aliases]" rows="3" class="large-text code" placeholder="<?php esc_attr_e( 'one per line — every way it shows up in speech, invoices, notes', 'zorderz' ); ?>"><?php echo esc_textarea( self::aliases_to_text( $cur['aliases'] ?? [] ) ); ?></textarea>
				</td></tr>
				<tr><th><?php esc_html_e( 'Negative aliases', 'zorderz' ); ?></th><td>
					<textarea name="item[negative_aliases]" rows="2" class="large-text code" placeholder="<?php esc_attr_e( 'one per line — tokens whose presence PREVENTS a match', 'zorderz' ); ?>"><?php echo esc_textarea( self::aliases_to_text( $cur['negative_aliases'] ?? [] ) ); ?></textarea>
				</td></tr>
				<tr><th><?php esc_html_e( 'Sellable', 'zorderz' ); ?></th><td>
					<select name="item[sellable]">
						<?php foreach ( ZDZ_Item_Engine::SELLABLE as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $cur['sellable'] ?? 'standalone', $s ); ?>><?php echo esc_html( $s ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Unit nouns', 'zorderz' ); ?></th><td>
					<input type="text" name="item[unit_noun_singular]" value="<?php echo esc_attr( $cur['unit_noun_singular'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'singular', 'zorderz' ); ?>" class="small-text">
					<input type="text" name="item[unit_noun_plural]" value="<?php echo esc_attr( $cur['unit_noun_plural'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'plural', 'zorderz' ); ?>" class="small-text">
					<span class="description"><?php esc_html_e( 'used when phrasing counts — never hardcoded', 'zorderz' ); ?></span>
				</td></tr>
				<tr><th><?php esc_html_e( 'Counted', 'zorderz' ); ?></th><td><label><input type="checkbox" name="item[countable]" value="1" <?php checked( $cur['countable'] ?? false ); ?>> <?php esc_html_e( 'Include this item as a cross-app count category', 'zorderz' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Parent item', 'zorderz' ); ?></th><td><input type="text" name="item[parent_item_id]" class="regular-text" value="<?php echo esc_attr( $cur['parent_item_id'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'for a job line whose count comes from child lines', 'zorderz' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Pricing scheme', 'zorderz' ); ?></th><td>
					<select name="item[pricing_scheme_id]">
						<option value=""><?php esc_html_e( '— none —', 'zorderz' ); ?></option>
						<?php foreach ( ZDZ_Item_Engine::pricing_schemes() as $s ) : ?>
							<option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( $cur['pricing_scheme_id'] ?? '', $s['id'] ); ?>><?php echo esc_html( $s['name'] . ' (' . $s['method'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<tr><th><?php esc_html_e( 'Match priority', 'zorderz' ); ?></th><td><input type="number" name="item[match_priority]" value="<?php echo esc_attr( (string) ( $cur['match_priority'] ?? 50 ) ); ?>" class="small-text"></td></tr>
				<tr><th><?php esc_html_e( 'Attributes (JSON)', 'zorderz' ); ?></th><td><textarea name="item[attributes]" rows="3" class="large-text code" placeholder='{"brand":"","bench_payable":false}'><?php echo esc_textarea( isset( $cur['attributes'] ) && $cur['attributes'] ? wp_json_encode( $cur['attributes'] ) : '' ); ?></textarea></td></tr>
				<tr><th><?php esc_html_e( 'Consumes (JSON)', 'zorderz' ); ?></th><td><textarea name="item[consumes]" rows="2" class="large-text code" placeholder='[{"item_id":"...","qty_per_unit":1,"uom":"each"}]'><?php echo esc_textarea( isset( $cur['consumes'] ) && $cur['consumes'] ? wp_json_encode( $cur['consumes'] ) : '' ); ?></textarea></td></tr>
				<tr><th><?php esc_html_e( 'External refs (JSON)', 'zorderz' ); ?></th><td><textarea name="item[external_refs]" rows="2" class="large-text code" placeholder='{"freshbooks":"","nutshell":""}'><?php echo esc_textarea( isset( $cur['external_refs'] ) && $cur['external_refs'] ? wp_json_encode( $cur['external_refs'] ) : '' ); ?></textarea></td></tr>
				<tr><th><?php esc_html_e( 'Rules', 'zorderz' ); ?></th><td><textarea name="item[rules]" rows="2" class="large-text code" placeholder="<?php esc_attr_e( 'one rule attach-point per line', 'zorderz' ); ?>"><?php echo esc_textarea( implode( "\n", $cur['rules'] ?? [] ) ); ?></textarea></td></tr>
				<tr><th><?php esc_html_e( 'Active', 'zorderz' ); ?></th><td><label><input type="checkbox" name="item[active]" value="1" <?php checked( $cur ? $cur['active'] : true ); ?>> <?php esc_html_e( 'Active', 'zorderz' ); ?></label></td></tr>
			</tbody></table>
			<?php submit_button( $cur ? __( 'Update item', 'zorderz' ) : __( 'Add item', 'zorderz' ) ); ?>
		</form>
		<?php
	}

	private static function render_discovery() {
		$disc = ZDZ_Item_Engine::discover();
		?>
		<hr>
		<h2><?php esc_html_e( 'Discovery', 'zorderz' ); ?></h2>
		<p class="description" style="max-width:52em"><?php esc_html_e( 'When a system is connected, Zorderz can read how your catalog is already set up and propose Items and prices — named from your own data, with the reasoning shown. Nothing is applied without your yes, item by item, and it never editorialises about your prices.', 'zorderz' ); ?></p>
		<?php if ( empty( $disc['sources'] ) && empty( $disc['items'] ) ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'No connected sources are proposing a catalog right now. Connect a CRM, invoicing, or a price list to enable discovery.', 'zorderz' ); ?></p></div>
		<?php else : ?>
			<p><?php echo esc_html( sprintf( /* translators: 1: item count, 2: scheme count. */ __( '%1$d proposed items, %2$d proposed schemes. Review and approve each below.', 'zorderz' ), count( $disc['items'] ), count( $disc['schemes'] ) ) ); ?></p>
		<?php endif; ?>
		<?php
	}

	private static function render_sample_and_danger() {
		?>
		<hr>
		<h2><?php esc_html_e( 'Sample set', 'zorderz' ); ?></h2>
		<p class="description" style="max-width:52em"><?php esc_html_e( 'Optional, fictional demo data that exercises both types, the subtype scopes, the counts contract, and every pricing method. It is never loaded automatically. Clear it before you go live.', 'zorderz' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'zdz_ie_apply_sample' ); ?>
			<input type="hidden" name="zdz_ie_action" value="apply_sample">
			<p>
				<label><?php esc_html_e( 'Type SAMPLE to confirm:', 'zorderz' ); ?> <input type="text" name="confirm" class="small-text" autocomplete="off"></label>
				<?php submit_button( __( 'Load sample set', 'zorderz' ), 'secondary', 'submit', false ); ?>
			</p>
		</form>

		<h2 style="margin-top:2em"><?php esc_html_e( 'Empty the catalog', 'zorderz' ); ?></h2>
		<p class="description" style="max-width:52em"><?php esc_html_e( 'Remove every item, scheme and subtype. Use this to reset to the blank-slate default.', 'zorderz' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'zdz_ie_clear_catalog' ); ?>
			<input type="hidden" name="zdz_ie_action" value="clear_catalog">
			<p>
				<label><?php esc_html_e( 'Type CLEAR to confirm:', 'zorderz' ); ?> <input type="text" name="confirm" class="small-text" autocomplete="off"></label>
				<?php submit_button( __( 'Empty the catalog', 'zorderz' ), 'delete', 'submit', false ); ?>
			</p>
		</form>
		<?php
	}

	// ───────────────────────────────────────────────────────── helpers

	private static function type_select( $name, $current ) {
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( ZDZ_Item_Engine::TYPES as $t ) {
			echo '<option value="' . esc_attr( $t ) . '" ' . selected( $current, $t, false ) . '>' . esc_html( $t ) . '</option>';
		}
		echo '</select>';
	}

	private static function scope_select( $name, $current ) {
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( ZDZ_Item_Engine::SCOPES as $s ) {
			echo '<option value="' . esc_attr( $s ) . '" ' . selected( $current, $s, false ) . '>' . esc_html( $s ) . '</option>';
		}
		echo '</select>';
	}

	private static function edit_target( $key ) {
		return isset( $_GET[ $key ] ) ? ZDZ_Item_Engine::slug( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	private static function aliases_to_text( $aliases ) {
		$lines = [];
		foreach ( (array) $aliases as $a ) {
			$lines[] = is_array( $a ) ? (string) ( $a['value'] ?? '' ) : (string) $a;
		}
		return implode( "\n", array_filter( $lines ) );
	}

	private static function lines_to_list( $raw ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $raw ) ), fn( $v ) => '' !== $v ) );
	}

	/**
	 * Decode a JSON textarea. Blank => []. Invalid JSON is dropped to [] rather than
	 * saved raw; save_item/save_scheme re-encode from the array, so a fat-fingered
	 * blob can never corrupt a row.
	 */
	private static function decode_json_field( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return [];
		}
		$d = json_decode( $raw, true );
		return is_array( $d ) ? $d : [];
	}

	// ───────────────────────────────────────────────────────── notices

	private static function notice( $msg, $type = 'info' ) {
		self::$notices[] = [ 'msg' => $msg, 'type' => $type ];
		set_transient( 'zdz_ie_notices_' . get_current_user_id(), self::$notices, 60 );
	}

	private static function absorb_redirect_notice() {
		$stored = get_transient( 'zdz_ie_notices_' . get_current_user_id() );
		if ( is_array( $stored ) ) {
			self::$notices = array_merge( $stored, self::$notices );
			delete_transient( 'zdz_ie_notices_' . get_current_user_id() );
		}
	}

	private static function render_notices() {
		foreach ( self::$notices as $n ) {
			printf(
				'<div class="notice notice-%s inline"><p>%s</p></div>',
				esc_attr( $n['type'] ),
				wp_kses( $n['msg'], [ 'code' => [], 'strong' => [], 'br' => [], 'em' => [] ] )
			);
		}
		self::$notices = [];
	}
}

ZDZ_Item_Engine_Admin::init();
