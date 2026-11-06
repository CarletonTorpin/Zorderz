<?php
/**
 * ZJOB_Order_Forms_Admin — the business's supplier order forms, in Settings → Zorderz Jobs.
 *
 * An administrator adds a form as its map (JSON: the fields people fill and where each goes on the paper, see
 * ZJOB_Order_Paper) plus the supplier's own blank PDF and/or spreadsheet. Each blank is checked to be what it says it
 * is, kept in wp-content/uploads/zorderz-forms/ (never served directly) and pinned by its SHA-256 at upload, so a blank
 * that changes later is refused until it is uploaded again. Forms written by an Identity Pack (mappings file) appear
 * here too; their blanks are uploaded the same way.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Order_Forms_Admin {

	const CAP      = 'manage_options';
	const MAX_SIZE = 5242880; // 5 MB a blank

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_save' ) );
		add_action( 'zjob_settings_after', array( __CLASS__, 'render' ) );
	}

	/** Is this file a PDF? (Its first bytes, never its name.) PURE. */
	public static function looks_pdf( string $head ): bool {
		return 0 === strpos( $head, '%PDF-' );
	}

	/** Is this file a spreadsheet package (a zip with a workbook in it)? */
	public static function looks_xlsx( string $path ): bool {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$z = new ZipArchive();
		if ( true !== $z->open( $path ) ) {
			return false;
		}
		$ok = false !== $z->locateName( '[Content_Types].xml' ) && false !== $z->locateName( 'xl/workbook.xml' );
		$z->close();
		return $ok;
	}

	public static function maybe_save(): void {
		if ( empty( $_POST['zjob_forms_action'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'zorderz' ) );
		}
		check_admin_referer( 'zjob_order_forms' );
		$act   = sanitize_key( wp_unslash( $_POST['zjob_forms_action'] ) );
		$forms = get_option( ZJOB_Project_Orders::OPT_FORMS, array() );
		$forms = is_array( $forms ) ? $forms : array();

		if ( 'delete' === $act ) {
			$key = sanitize_key( wp_unslash( $_POST['zjob_form_key'] ?? '' ) );
			if ( isset( $forms[ $key ] ) ) {
				unset( $forms[ $key ] );
				update_option( ZJOB_Project_Orders::OPT_FORMS, $forms, false );
				add_settings_error( 'zjob', 'form_deleted', __( 'Form removed. Orders already made on it keep their record.', 'zorderz' ), 'updated' );
			}
			return;
		}
		if ( 'save' !== $act ) {
			return;
		}
		$key = sanitize_key( wp_unslash( $_POST['zjob_form_key'] ?? '' ) );
		if ( '' === $key || 'materials' === $key ) {
			add_settings_error( 'zjob', 'form_key', __( 'Give the form a short key (letters, digits, dashes), other than "materials".', 'zorderz' ) );
			return;
		}
		$map = json_decode( wp_unslash( (string) ( $_POST['zjob_form_map'] ?? '' ) ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce and capability checked above; the administrator's JSON map is validated by ZJOB_Project_Orders::clean_form() here and on every read.
		if ( ! is_array( $map ) ) {
			$map = isset( $forms[ $key ] ) && is_array( $forms[ $key ] ) ? $forms[ $key ] : null;
		}
		if ( ! is_array( $map ) ) {
			add_settings_error( 'zjob', 'form_map', __( 'The form map is not valid JSON.', 'zorderz' ) );
			return;
		}
		$paper = is_array( $map['paper'] ?? null ) ? $map['paper'] : array();
		foreach ( array( 'pdf', 'xlsx' ) as $kind ) {
			$f = $_FILES[ 'zjob_form_' . $kind ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below.
			if ( ! is_array( $f ) || empty( $f['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $f['error'] ?? 1 ) ) {
				continue;
			}
			$tmp = (string) $f['tmp_name'];
			if ( ! is_uploaded_file( $tmp ) || filesize( $tmp ) > self::MAX_SIZE ) {
				add_settings_error( 'zjob', 'form_file', __( 'A blank was too large or did not arrive.', 'zorderz' ) );
				continue;
			}
			$head = (string) file_get_contents( $tmp, false, null, 0, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( 'pdf' === $kind ? ! self::looks_pdf( $head ) : ! self::looks_xlsx( $tmp ) ) {
				add_settings_error( 'zjob', 'form_type', 'pdf' === $kind ? __( 'The PDF blank is not a PDF.', 'zorderz' ) : __( 'The spreadsheet blank is not an .xlsx file.', 'zorderz' ) );
				continue;
			}
			$dir = ZJOB_Order_Paper::ensure_site_dir();
			$sha = (string) hash_file( 'sha256', $tmp );
			$dst = $key . '-' . substr( $sha, 0, 12 ) . '.' . $kind;
			if ( '' === $dir || ! move_uploaded_file( $tmp, $dir . $dst ) ) { // sentinel:allow administrator upload of a supplier form blank: is_uploaded_file, size and magic checked, private directory
				add_settings_error( 'zjob', 'form_store', __( 'The blank could not be stored.', 'zorderz' ) );
				continue;
			}
			$paper[ $kind ]           = is_array( $paper[ $kind ] ?? null ) ? $paper[ $kind ] : array();
			$paper[ $kind ]['where']  = 'site';
			$paper[ $kind ]['file']   = $dst;
			$paper[ $kind ]['sha256'] = $sha;
		}
		if ( $paper ) {
			$map['paper'] = $paper;
		}
		if ( null === ZJOB_Project_Orders::clean_form( $map ) ) {
			add_settings_error( 'zjob', 'form_fields', __( 'The form needs at least one field people fill in (unit_fields, each with k and label).', 'zorderz' ) );
			return;
		}
		$forms[ $key ] = $map;
		update_option( ZJOB_Project_Orders::OPT_FORMS, $forms, false );
		ZJOB_Project_Orders::flush_memo();
		add_settings_error( 'zjob', 'form_saved', __( 'Form saved.', 'zorderz' ), 'updated' );
	}

	/** One form's paper in words: which blanks it has, and whether each still matches its pin. */
	public static function paper_words( ?array $paper ): string {
		if ( ! $paper ) {
			return __( 'no paper: a list on screen only', 'zorderz' );
		}
		$bits = array();
		foreach ( array( 'pdf' => 'PDF', 'xlsx' => 'Excel' ) as $kind => $label ) {
			if ( empty( $paper[ $kind ] ) ) {
				continue;
			}
			$bits[] = $label . ': ' . ( ZJOB_Order_Paper::blank_ok( $paper, $kind ) ? __( 'ready', 'zorderz' ) : __( 'blank missing or changed, upload it again', 'zorderz' ) );
		}
		return implode( ' · ', $bits );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$stored = get_option( ZJOB_Project_Orders::OPT_FORMS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		?>
		<h2 id="zjob-order-forms"><?php esc_html_e( 'Supplier order forms', 'zorderz' ); ?></h2>
		<p style="max-width:740px"><?php esc_html_e( 'A material order can be printed on a supplier\'s own form: a filled PDF and/or the supplier\'s spreadsheet with the cells written in. Add each form as its map (JSON) with its blank. The built-in "Materials list" prints on a plain sample form.', 'zorderz' ); ?></p>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th><?php esc_html_e( 'Key', 'zorderz' ); ?></th><th><?php esc_html_e( 'Form', 'zorderz' ); ?></th><th><?php esc_html_e( 'Paper', 'zorderz' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( ZJOB_Project_Orders::forms() as $k => $f ) : ?>
				<tr>
					<td><code><?php echo esc_html( $k ); ?></code></td>
					<td><?php echo esc_html( $f['label'] ); ?></td>
					<td><?php echo esc_html( self::paper_words( $f['paper'] ?? null ) ); ?></td>
					<td>
						<?php if ( isset( $stored[ $k ] ) ) : ?>
						<form method="post" style="display:inline">
							<?php wp_nonce_field( 'zjob_order_forms' ); ?>
							<input type="hidden" name="zjob_form_key" value="<?php echo esc_attr( $k ); ?>">
							<button type="submit" class="button-link-delete" name="zjob_forms_action" value="delete"><?php esc_html_e( 'Remove', 'zorderz' ); ?></button>
						</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<h3><?php esc_html_e( 'Add or update a form', 'zorderz' ); ?></h3>
		<form method="post" enctype="multipart/form-data" style="max-width:900px">
			<?php wp_nonce_field( 'zjob_order_forms' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="zjob_form_key"><?php esc_html_e( 'Key', 'zorderz' ); ?></label></th>
					<td><input id="zjob_form_key" name="zjob_form_key" type="text" class="regular-text" placeholder="supplier-a-doors"></td></tr>
				<tr><th scope="row"><label for="zjob_form_map"><?php esc_html_e( 'Map (JSON)', 'zorderz' ); ?></label></th>
					<td><textarea id="zjob_form_map" name="zjob_form_map" rows="8" class="large-text code" placeholder='{"label":"Supplier A doors","supplier":"supplier-a","unit_fields":[{"k":"item","label":"Item","required":true},{"k":"qty","label":"Qty","type":"number"}],"paper":{"name":"Supplier-A","pdf":{"w":612,"h":792,"layout":"rows","po":[400,30,570,46],"unit_rows":[[200,218]],"unit_cols":{"item":[36,300],"qty":[300,360]}}}}'></textarea>
					<p class="description"><?php esc_html_e( 'Leave empty to keep the stored map and only replace a blank. Boxes are [left, top, right, bottom] in points from the top-left of the page; cells are like "B12".', 'zorderz' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Blank PDF', 'zorderz' ); ?></th><td><input name="zjob_form_pdf" type="file" accept="application/pdf"></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Blank spreadsheet', 'zorderz' ); ?></th><td><input name="zjob_form_xlsx" type="file" accept=".xlsx"></td></tr>
			</table>
			<p><button type="submit" name="zjob_forms_action" value="save" class="button button-primary"><?php esc_html_e( 'Save form', 'zorderz' ); ?></button></p>
		</form>
		<?php
	}
}
