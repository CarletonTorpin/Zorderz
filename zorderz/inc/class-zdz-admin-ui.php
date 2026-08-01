<?php
/**
 * Admin interface for per-user app permissions
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Admin_UI {

	public function __construct() {
		add_action( 'show_user_profile', [ $this, 'render_app_permissions' ] );
		add_action( 'edit_user_profile', [ $this, 'render_app_permissions' ] );
		add_action( 'personal_options_update', [ $this, 'save_app_permissions' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save_app_permissions' ] );

		// v2.14.3: One-time migration - convert legacy numeric zdz_allowed_apps
		// entries to stable string config IDs. Previous versions indexed apps by
		// their numeric array position, which broke when plugins were
		// activated/deactivated and also caused strict in_array() mismatches
		// (integer keys vs. string-serialised form values).
		add_action( 'admin_init', [ $this, 'migrate_legacy_allowed_apps' ] );
	}

	/**
	 * v2.14.3: One-time migration for zdz_allowed_apps user meta.
	 *
	 * Detects users whose zdz_allowed_apps still contain numeric values
	 * (legacy format) and converts them to string config IDs by mapping
	 * the old numeric index to the current plugin registration order.
	 *
	 * Runs once, then sets an option flag so it doesn't repeat.
	 */
	public function migrate_legacy_allowed_apps() {
		if ( get_option( 'zdz_migrated_allowed_apps_v2143', false ) ) {
			return;
		}
		if ( ! class_exists( 'ZDZ_Plugin_API' ) ) {
			return;
		}

		// Build a numeric-index -> string-ID lookup from the current registration order.
		// This matches the old behaviour where apps were indexed by their position
		// in the apply_filters array (0, 1, 2, ...).
		$raw_apps = apply_filters( 'zdz_register_apps', [] );
		$index_map = [];
		$i = 0;
		foreach ( $raw_apps as $app ) {
			if ( $app instanceof \Zorderz\App_Interface ) {
				$config = $app->get_config();
				if ( ! empty( $config['id'] ) ) {
					$index_map[ $i ]             = $config['id'];
					$index_map[ (string) $i ]    = $config['id'];
				}
				$i++;
			}
		}

		if ( empty( $index_map ) ) {
			update_option( 'zdz_migrated_allowed_apps_v2143', true );
			return;
		}

		// Find all users who have zdz_allowed_apps set.
		$users = get_users( [
			'meta_key'  => 'zdz_allowed_apps',
			'fields'    => 'ID',
		] );

		foreach ( $users as $uid ) {
			$allowed = get_user_meta( $uid, 'zdz_allowed_apps', true );
			if ( ! is_array( $allowed ) || empty( $allowed ) ) {
				continue;
			}

			$needs_update = false;
			$migrated     = [];
			foreach ( $allowed as $entry ) {
				if ( is_numeric( $entry ) && isset( $index_map[ $entry ] ) ) {
					$migrated[]   = $index_map[ $entry ];
					$needs_update = true;
				} elseif ( is_string( $entry ) && ! is_numeric( $entry ) ) {
					// Already a string ID - keep it.
					$migrated[] = $entry;
				} else {
					// Unknown format - keep as-is for safety.
					$migrated[]   = $entry;
					$needs_update = true;
				}
			}

			if ( $needs_update ) {
				update_user_meta( $uid, 'zdz_allowed_apps', array_unique( $migrated ) );
			}
		}

		// Also migrate zdz_denied_apps with the same logic.
		$denied_users = get_users( [
			'meta_key'  => 'zdz_denied_apps',
			'fields'    => 'ID',
		] );
		foreach ( $denied_users as $uid ) {
			$denied = get_user_meta( $uid, 'zdz_denied_apps', true );
			if ( ! is_array( $denied ) || empty( $denied ) ) {
				continue;
			}
			$needs_update = false;
			$migrated     = [];
			foreach ( $denied as $entry ) {
				if ( is_numeric( $entry ) && isset( $index_map[ $entry ] ) ) {
					$migrated[]   = $index_map[ $entry ];
					$needs_update = true;
				} else {
					$migrated[] = $entry;
				}
			}
			if ( $needs_update ) {
				update_user_meta( $uid, 'zdz_denied_apps', array_unique( $migrated ) );
			}
		}

		update_option( 'zdz_migrated_allowed_apps_v2143', true );
	}

	public function render_app_permissions( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$allowed_apps = get_user_meta( $user->ID, 'zdz_allowed_apps', true );
		if ( ! is_array( $allowed_apps ) ) {
			$allowed_apps = [];
		}

		$all_apps = class_exists( 'ZDZ_Plugin_API' ) ? ZDZ_Plugin_API::get_instance()->get_all_apps() : [];

		wp_nonce_field( 'zdz_save_app_permissions', 'zdz_app_permissions_nonce' );
		?>
		<h3><?php esc_html_e( 'Zorderz App Permissions', 'zorderz' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Allowed Apps', 'zorderz' ); ?></label></th>
				<td>
					<?php if ( class_exists( 'ZDZ_User_Roles' ) && ZDZ_User_Roles::is_admin_role( $user->roles[0] ?? '' ) ) : ?>
						<p><em><?php esc_html_e( 'All Apps (Administrator)', 'zorderz' ); ?></em></p>
					<?php else : ?>
						<?php if ( empty( $all_apps ) ) : ?>
							<p><?php esc_html_e( 'No apps registered.', 'zorderz' ); ?></p>
						<?php else : ?>
							<?php foreach ( $all_apps as $app_id => $app ) :
								$config = $app->get_config();
								$checked = in_array( $app_id, $allowed_apps, true ) ? 'checked' : '';
							?>
								<label style="display:block; margin-bottom:5px;">
									<input type="checkbox" name="zdz_allowed_apps[]" value="<?php echo esc_attr( $app_id ); ?>" <?php echo $checked; ?>>
									<?php echo esc_html( $config['nm'] ?? $app_id ); ?>
								</label>
							<?php endforeach; ?>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<?php // -- v2.17.0: Data Permissions (per-user override of role defaults) -- ?>
		<?php if ( class_exists( 'ZDZ_Data_Permissions' ) ) : ?>
		<h3><?php esc_html_e( 'Zorderz Data Permissions', 'zorderz' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Override role-based defaults for data access across all plugins. "Default" uses the role\'s built-in setting.', 'zorderz' ); ?></p>
		<table class="form-table">
		<?php
			$dp_overrides = get_user_meta( $user->ID, 'zdz_data_permissions', true );
			if ( ! is_array( $dp_overrides ) ) $dp_overrides = [];
			$user_role = $user->roles[0] ?? 'subscriber';
			$role_defaults = ZDZ_Data_Permissions::ROLE_DEFAULTS[ $user_role ]
				?? ZDZ_Data_Permissions::ROLE_DEFAULTS['zdz_tech'];
			if ( 'administrator' === $user_role ) {
				$role_defaults = ZDZ_Data_Permissions::ROLE_DEFAULTS['zdz_admin'];
			}
			$dp_labels = [
				'view_company_revenue'      => 'Company Revenue Figures',
				'view_others_data'          => 'Other Employees\' Data',
				'view_own_commission'       => 'Own Commission Details',
				'view_others_commissions'   => 'Others\' Commission Details',
				'run_commission_calculation' => 'Run Commission Calculations',
				'edit_cogs_catalog'         => 'Edit COGS Catalog',
				'view_commission_audit_trail'=> 'Commission Audit Trail',
				'access_web_research'       => 'Web Research Queries',
				'access_deep_research'      => 'Deep Research Queries',
				'upload_to_knowledge_vault' => 'Knowledge Vault Uploads',
				'lead_crew'                 => 'Crew Lead - assign work to & oversee a crew',
				'handoff_jobs'              => 'Hand off job components to another tech',
			];
			foreach ( ZDZ_Data_Permissions::ALL_KEYS as $dp_key ) :
				$current  = $dp_overrides[ $dp_key ] ?? 'default';
				$role_val = $role_defaults[ $dp_key ] ?? 'deny';
		?>
		<tr>
			<th><label for="zdz_dp_<?php echo esc_attr( $dp_key ); ?>"><?php
				echo esc_html( $dp_labels[ $dp_key ] ?? $dp_key );
			?></label></th>
			<td>
				<select name="zdz_data_permissions[<?php echo esc_attr( $dp_key ); ?>]"
						id="zdz_dp_<?php echo esc_attr( $dp_key ); ?>">
					<option value="default" <?php selected( $current, 'default' ); ?>>
						Default (<?php echo esc_html( ucfirst( $role_val ) ); ?>)
					</option>
					<option value="allow" <?php selected( $current, 'allow' ); ?>>Allow</option>
					<option value="deny" <?php selected( $current, 'deny' ); ?>>Deny</option>
				</select>
			</td>
		</tr>
		<?php endforeach; ?>
		</table>
		<?php endif; ?>

		<?php // -- v2.32.0: Crew (hierarchy) - who this person leads ("ADD A SPECIFIC USER") -- ?>
		<?php if ( class_exists( 'ZDZ_Hierarchy' ) ) :
			// A user can be given a crew when they are (or can be) a Crew Lead.
			// We show the picker whenever the resolved lead_crew cap is allow, OR
			// they are admin, OR they already have a crew - so an admin can build
			// the relationship right here on the profile screen.
			$is_lead_capable = ZDZ_Hierarchy::is_crew_lead( (int) $user->ID );
			$existing_crew   = ZDZ_Hierarchy::get_crew( (int) $user->ID );
			$reports_to      = ZDZ_Hierarchy::get_lead( (int) $user->ID );
			if ( $is_lead_capable || ! empty( $existing_crew ) ) :
				// Candidate crew members = every other app-capable user (exclude self,
				// exclude admins/owners - you don't put an owner in someone's crew).
				$candidates = get_users( [
					'fields'  => [ 'ID', 'display_name' ],
					'number'  => 500,
					'orderby' => 'display_name',
					'order'   => 'ASC',
				] );
		?>
		<h3><?php esc_html_e( 'Zorderz Crew (who this person leads)', 'zorderz' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Select the people this Crew Lead is in charge of. They can assign work to, check in on, and manage the work of everyone in their crew. Requires the "Crew Lead" data permission above (set it to Allow to enable).', 'zorderz' ); ?></p>
		<?php // Hidden marker so a fully-deselected list still clears the crew on save. ?>
		<input type="hidden" name="zdz_crew_present" value="1" />
		<table class="form-table">
			<tr>
				<th><label for="zdz_crew_members"><?php esc_html_e( 'Crew Members', 'zorderz' ); ?></label></th>
				<td>
					<select name="zdz_crew_members[]" id="zdz_crew_members" multiple size="8" style="min-width:280px;">
					<?php foreach ( $candidates as $cand ) :
						$cid = (int) $cand->ID;
						if ( $cid === (int) $user->ID ) { continue; } // never yourself
						if ( class_exists( 'ZDZ_User_Roles' ) ) {
							$c_user = get_userdata( $cid );
							$c_role = $c_user ? ( $c_user->roles[0] ?? '' ) : '';
							if ( ZDZ_User_Roles::is_admin_role( $c_role ) ) { continue; } // no owners/admins as crew
						}
						$sel = in_array( $cid, $existing_crew, true ) ? 'selected' : '';
					?>
						<option value="<?php echo esc_attr( $cid ); ?>" <?php echo $sel; ?>>
							<?php echo esc_html( $cand->display_name ); ?>
						</option>
					<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Ctrl/Cmd-click to select multiple. A person can report to only one Crew Lead - assigning them here moves them.', 'zorderz' ); ?></p>
				</td>
			</tr>
			<?php if ( $reports_to ) :
				$lead_user = get_userdata( $reports_to ); ?>
			<tr>
				<th><?php esc_html_e( 'Reports to', 'zorderz' ); ?></th>
				<td><em><?php echo esc_html( $lead_user ? $lead_user->display_name : ( '#' . $reports_to ) ); ?></em>
				<p class="description"><?php esc_html_e( 'This user is currently a member of the above person\'s crew.', 'zorderz' ); ?></p></td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
			endif; // lead-capable
		endif; // ZDZ_Hierarchy exists
		?>

		<?php // -- v2.13.0 additive fields (shown for all roles) -- ?>
		<h3><?php esc_html_e( 'Zorderz Personalization', 'zorderz' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label for="zdz_salesperson_initials"><?php esc_html_e( 'Salesperson Initials', 'zorderz' ); ?></label></th>
				<td>
					<input
						type="text"
						id="zdz_salesperson_initials"
						name="zdz_salesperson_initials"
						value="<?php echo esc_attr( get_user_meta( $user->ID, 'zdz_salesperson_initials', true ) ); ?>"
						class="regular-text"
						maxlength="8"
					/>
					<p class="description"><?php esc_html_e( 'Used by reporting tools to filter FreshBooks data by user. CASE-SENSITIVE - match exactly what appears on invoices (e.g. "TC", not "tc").', 'zorderz' ); ?></p>
				</td>
			</tr>
		</table>

		<?php // -- v2.20.0 r4: Extended profile fields for cross-plugin use -- ?>
		<h3><?php esc_html_e( 'Zorderz Profile', 'zorderz' ); ?></h3>
		<p class="description"><?php esc_html_e( 'These fields are used by the Lead Generator, Estimate Creator, and other plugins for personalization.', 'zorderz' ); ?></p>
		<table class="form-table">
			<tr>
				<th><label for="tsl_salesperson_code"><?php esc_html_e( 'Salesperson Code', 'zorderz' ); ?></label></th>
				<td>
					<input type="text" id="tsl_salesperson_code" name="tsl_salesperson_code"
						value="<?php echo esc_attr( get_user_meta( $user->ID, 'tsl_salesperson_code', true ) ); ?>"
						class="regular-text" maxlength="10" placeholder="e.g. AS" />
					<p class="description"><?php esc_html_e( 'Short code used to match this user to territories and lead batches (e.g. "AS" for a salesperson). Overrides username-based matching.', 'zorderz' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="zdz_user_phone"><?php esc_html_e( 'Phone Number', 'zorderz' ); ?></label></th>
				<td>
					<input type="tel" id="zdz_user_phone" name="zdz_user_phone"
						value="<?php echo esc_attr( get_user_meta( $user->ID, 'zdz_user_phone', true ) ); ?>"
						class="regular-text" placeholder="e.g. 619-555-1234" />
					<p class="description"><?php esc_html_e( 'Direct phone number for this user. Used in email sign-offs and lead contact cards.', 'zorderz' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="zdz_user_email_name"><?php esc_html_e( 'Email Sign-Off Name', 'zorderz' ); ?></label></th>
				<td>
					<input type="text" id="zdz_user_email_name" name="zdz_user_email_name"
						value="<?php echo esc_attr( get_user_meta( $user->ID, 'zdz_user_email_name', true ) ); ?>"
						class="regular-text" placeholder="<?php echo esc_attr( $user->display_name ); ?>" />
					<p class="description"><?php esc_html_e( 'How this person signs off emails — a first name, a full name, or a name plus role. Defaults to their display name if left empty.', 'zorderz' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="zdz_user_territories"><?php esc_html_e( 'Territories', 'zorderz' ); ?></label></th>
				<td>
					<input type="text" id="zdz_user_territories" name="zdz_user_territories"
						value="<?php echo esc_attr( implode( ', ', (array) ( get_user_meta( $user->ID, 'zdz_user_territories', true ) ?: [] ) ) ); ?>"
						class="regular-text" placeholder="e.g. AS, NSD, EC" />
					<p class="description"><?php esc_html_e( 'Comma-separated territory codes this person covers. Used by the Lead Generator to auto-filter lead batches.', 'zorderz' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_app_permissions( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// v2.12.3: optional nonce - capability check above still gates us.
		if ( isset( $_POST['zdz_app_permissions_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zdz_app_permissions_nonce'] ) ), 'zdz_save_app_permissions' ) ) {
				return;
			}
		}

		// v2.13.0: save salesperson initials for ALL roles (admins included).
		// CASE-SENSITIVE on purpose - matches FreshBooks invoice data exactly.
		if ( isset( $_POST['zdz_salesperson_initials'] ) ) {
			$raw   = wp_unslash( (string) $_POST['zdz_salesperson_initials'] );
			$clean = preg_replace( '/[^\p{L}\p{N}]/u', '', $raw ) ?? '';
			$clean = substr( $clean, 0, 8 );
			update_user_meta( $user_id, 'zdz_salesperson_initials', $clean );
		}

		// v2.20.0 r4: Save extended profile fields for cross-plugin use.
		if ( isset( $_POST['tsl_salesperson_code'] ) ) {
			$code = sanitize_text_field( wp_unslash( $_POST['tsl_salesperson_code'] ) );
			$code = substr( $code, 0, 10 );
			update_user_meta( $user_id, 'tsl_salesperson_code', $code );
		}

		if ( isset( $_POST['zdz_user_phone'] ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST['zdz_user_phone'] ) );
			// Strip everything except digits, dashes, parens, plus, spaces
			$phone = preg_replace( '/[^\d\-\+\(\)\s]/', '', $phone );
			update_user_meta( $user_id, 'zdz_user_phone', $phone );
		}

		if ( isset( $_POST['zdz_user_email_name'] ) ) {
			$email_name = sanitize_text_field( wp_unslash( $_POST['zdz_user_email_name'] ) );
			update_user_meta( $user_id, 'zdz_user_email_name', $email_name );
		}

		if ( isset( $_POST['zdz_user_territories'] ) ) {
			$raw_territories = sanitize_text_field( wp_unslash( $_POST['zdz_user_territories'] ) );
			// Parse comma-separated codes into an array, trim each
			$territories = array_filter( array_map( 'trim', explode( ',', $raw_territories ) ) );
			// Uppercase and limit to valid code format
			$territories = array_map( function( $t ) {
				return substr( strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $t ) ), 0, 10 );
			}, $territories );
			update_user_meta( $user_id, 'zdz_user_territories', array_values( array_unique( $territories ) ) );
		}

		// -- v2.32.0: Save the Crew (hierarchy). This is intentionally saved for
		// ALL roles (admins included, since an admin could hypothetically lead a
		// crew) - the picker only renders for lead-capable users, and set_crew()
		// re-guards the actor + validates every member id. We only touch crew meta
		// when the field is present (i.e. the section was rendered), so saving an
		// unrelated profile tab never clears a crew.
		if ( isset( $_POST['zdz_crew_members'] ) && class_exists( 'ZDZ_Hierarchy' ) ) {
			$raw_crew = wp_unslash( $_POST['zdz_crew_members'] );
			$member_ids = is_array( $raw_crew ) ? array_map( 'intval', $raw_crew ) : [];
			ZDZ_Hierarchy::set_crew( (int) $user_id, $member_ids, get_current_user_id() );
		} elseif ( class_exists( 'ZDZ_Hierarchy' ) && isset( $_POST['zdz_crew_present'] ) ) {
			// The section rendered but nothing was selected -> clear the crew.
			ZDZ_Hierarchy::set_crew( (int) $user_id, [], get_current_user_id() );
		}

		$user = get_userdata( $user_id );
		if ( class_exists( 'ZDZ_User_Roles' ) && ZDZ_User_Roles::is_admin_role( $user->roles[0] ?? '' ) ) {
			return; // Admin-level roles have all apps, don't save specific meta to override
		}

		$apps = isset( $_POST['zdz_allowed_apps'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['zdz_allowed_apps'] ) ) : [];
		update_user_meta( $user_id, 'zdz_allowed_apps', $apps );

		// v2.17.0: Save data permissions overrides
		if ( isset( $_POST['zdz_data_permissions'] ) && is_array( $_POST['zdz_data_permissions'] ) && class_exists( 'ZDZ_Data_Permissions' ) ) {
			$raw_perms   = wp_unslash( $_POST['zdz_data_permissions'] );
			$clean_perms = [];
			$valid_vals  = [ 'default', 'allow', 'deny' ];
			foreach ( ZDZ_Data_Permissions::ALL_KEYS as $key ) {
				$val = $raw_perms[ $key ] ?? 'default';
				$clean_perms[ $key ] = in_array( $val, $valid_vals, true ) ? $val : 'default';
			}
			update_user_meta( $user_id, 'zdz_data_permissions', $clean_perms );
		}
	}
}

new ZDZ_Admin_UI();
