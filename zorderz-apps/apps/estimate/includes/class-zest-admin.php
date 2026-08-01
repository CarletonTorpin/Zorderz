<?php
/**
 * ZEST_Admin — thin admin glue for the Estimates module.
 *
 * The module stores NO credentials — FreshBooks / Nutshell / Poe secrets live in the
 * theme's Connections layer (ZDZ_Core_Settings). This class only:
 *   - renders the per-party document preferences on the user profile (the short code, the
 *     parenthetical, and the measurement-notation profile — crosswalk D3/D8/D15), saving
 *     them to zdz_* meta that ZDZ_Party / ZDZ_Doc_Conventions read;
 *   - resolves the model registry through a filter (no hardcoded model list).
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Admin {

	public static function init(): void {
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_fields' ) );
	}

	/**
	 * The model registry (id => label) for any admin picker. Owned centrally by the
	 * analytics module and exposed via a filter — this module ships NO model names, so an
	 * install with no registry simply shows the provider default.
	 */
	public static function model_registry(): array {
		return (array) apply_filters( 'zdz_ai_model_registry', array() );
	}

	public static function render_profile_fields( $user ): void {
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}
		$code  = (string) get_user_meta( $user->ID, 'zdz_user_initials', true );
		$paren = (string) get_user_meta( $user->ID, 'zdz_user_parenthetical', true );
		$notation = class_exists( 'ZDZ_Doc_Conventions' )
			? (string) get_user_meta( $user->ID, 'zdz_notation_profile', true ) : '';
		$notation_hint = class_exists( 'ZDZ_Doc_Conventions' )
			? (string) ZDZ_Doc_Conventions::get( 'measurement_notation.example', '' ) : '';
		?>
		<h2><?php esc_html_e( 'Estimate document preferences', 'zorderz' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="zdz_user_initials"><?php esc_html_e( 'Short code (initials)', 'zorderz' ); ?></label></th>
				<td>
					<input type="text" name="zdz_user_initials" id="zdz_user_initials"
						value="<?php echo esc_attr( $code ); ?>" class="regular-text" maxlength="4" />
					<p class="description"><?php esc_html_e( 'Your rep code, used on the location line and attribution. Stored without delimiters (e.g. GT, not (GT)).', 'zorderz' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="zdz_user_parenthetical"><?php esc_html_e( 'Document parenthetical', 'zorderz' ); ?></label></th>
				<td>
					<input type="text" name="zdz_user_parenthetical" id="zdz_user_parenthetical"
						value="<?php echo esc_attr( $paren ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Optional override for how your code prints on documents.', 'zorderz' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="zdz_notation_profile"><?php esc_html_e( 'Measurement notation', 'zorderz' ); ?></label></th>
				<td>
					<textarea name="zdz_notation_profile" id="zdz_notation_profile" rows="3" class="large-text"><?php echo esc_textarea( $notation ); ?></textarea>
					<?php if ( '' !== $notation_hint ) : ?>
						<p class="description"><?php echo esc_html( sprintf( /* translators: example notation */ __( 'Example: %s', 'zorderz' ), $notation_hint ) ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_profile_fields( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( isset( $_POST['zdz_user_initials'] ) ) {
			update_user_meta( $user_id, 'zdz_user_initials', strtoupper( sanitize_text_field( wp_unslash( $_POST['zdz_user_initials'] ) ) ) );
		}
		if ( isset( $_POST['zdz_user_parenthetical'] ) ) {
			update_user_meta( $user_id, 'zdz_user_parenthetical', sanitize_text_field( wp_unslash( $_POST['zdz_user_parenthetical'] ) ) );
		}
		if ( isset( $_POST['zdz_notation_profile'] ) ) {
			update_user_meta( $user_id, 'zdz_notation_profile', sanitize_textarea_field( wp_unslash( $_POST['zdz_notation_profile'] ) ) );
		}
	}
}
