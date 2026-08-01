<?php
/**
 * ZL Permissions — Role-based & username-based feature gating.
 *
 * ARCHITECTURE ROLE:
 * Central permission authority for all plugin features. Determines what each
 * user can see and do in both the backend dashboard and the frontend widget.
 *
 * DESIGN:
 * - Permissions are stored in wp_options as JSON (zl_permissions).
 * - Each "feature" is a string key (e.g. 'can_generate_full', 'view_pricing').
 * - Roles get a default set of features.
 * - Per-username overrides can grant ('+feature') or revoke ('!feature') specific capabilities.
 * - Admin (manage_options or ts_admin) always has all features unless explicitly revoked by username.
 *
 * FUTURE-PROOFING:
 * The 'view_pricing' and 'edit_pricing' features are defined now but unused until
 * the estimate pricing module is built. When that happens, the permission checks
 * are already wired into the AJAX responses and JS rendering.
 *
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Permissions {

	/**
	 * wp_options key for the permissions configuration.
	 */
	const OPTION_KEY = 'zl_permissions';

	/**
	 * All known feature keys with human-readable labels and descriptions.
	 * Grouped by category for the admin settings UI.
	 *
	 * @return array
	 */
	public static function get_feature_definitions() {
		return array(
			'generation' => array(
				'label'    => 'Lead Generation',
				'features' => array(
					'can_generate_test' => array(
						'label' => 'Generate Test Batches',
						'desc'  => 'Run test generation (3 leads, not pushed to CRM).',
					),
					'can_generate_full' => array(
						'label' => 'Generate Full Batches',
						'desc'  => 'Run full generation (pushes leads to Nutshell CRM).',
					),
					'can_edit_filters' => array(
						'label' => 'Edit Generation Filters',
						'desc'  => 'Change salesperson, lookback, product filter, city/zip, spend, demographic.',
					),
				),
			),
			'crm' => array(
				'label'    => 'CRM & Lead Management',
				'features' => array(
					'can_sync_nutshell' => array(
						'label' => 'Sync with Nutshell',
						'desc'  => 'Pull latest lead statuses from Nutshell CRM.',
					),
					'can_send_to_nutshell' => array(
						'label' => 'Send Test to Nutshell',
						'desc'  => 'Promote test batch leads to real Nutshell leads.',
					),
					'can_mark_contacted' => array(
						'label' => 'Mark Leads Contacted/Skipped',
						'desc'  => 'Update the contact status of individual leads.',
					),
					'can_delete_batch' => array(
						'label' => 'Delete Batches',
						'desc'  => 'Permanently remove batches and their leads.',
					),
				),
			),
			'viewing' => array(
				'label'    => 'Data Visibility',
				'features' => array(
					'view_batch_history' => array(
						'label' => 'View Batch History',
						'desc'  => 'See the list of past batches and their stats.',
					),
					'view_lead_details' => array(
						'label' => 'View Lead Details',
						'desc'  => 'Expand batches to see individual lead cards.',
					),
					'view_contact_info' => array(
						'label' => 'View Contact Info',
						'desc'  => 'See email addresses and phone numbers on lead cards.',
					),
					'view_pricing' => array(
						'label' => 'View Pricing / Amounts',
						'desc'  => 'See dollar amounts in purchase history and spend data. (Future: estimate pricing.)',
					),
					'edit_pricing' => array(
						'label' => 'Edit Pricing / Amounts',
						'desc'  => 'Modify estimate pricing values. (Future feature — reserved.)',
					),
				),
			),
			// v2.0.0: Filter visibility and interaction features
			'filters' => array(
				'label'    => 'Filter Controls',
				'features' => array(
					'can_choose_salesperson' => array(
						'label' => 'Choose Salesperson',
						'desc'  => 'Show the salesperson dropdown. Sales users are auto-assigned.',
					),
					'can_filter_spend' => array(
						'label' => 'Filter by Spend Range',
						'desc'  => 'Show the spend range filter fields.',
					),
					'can_filter_demographic' => array(
						'label' => 'Filter by Demographic',
						'desc'  => 'Show the gender/demographic filter.',
					),
				),
			),
			'interaction' => array(
				'label'    => 'Lead Interaction',
				'features' => array(
					'can_update_status' => array(
						'label' => 'Update Lead Status',
						'desc'  => 'Mark leads as contacted, skipped, or schedule callbacks.',
					),
					'can_forward_note' => array(
						'label' => 'Forward Notes',
						'desc'  => 'Forward lead info to team members.',
					),
					'can_view_notes' => array(
						'label' => 'View Nutshell Notes',
						'desc'  => 'See Nutshell CRM timeline notes for leads.',
					),
				),
			),
		);
	}

	/**
	 * Flat list of all feature keys.
	 *
	 * @return string[]
	 */
	public static function get_all_feature_keys() {
		$keys = array();
		foreach ( self::get_feature_definitions() as $group ) {
			$keys = array_merge( $keys, array_keys( $group['features'] ) );
		}
		return $keys;
	}

	/**
	 * Default permission configuration.
	 * Used on first install (v1.5.0 migration) or if option is missing.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'roles' => array(
				'zdz_owner' => array( 'all' ),
				'zdz_admin' => array( 'all' ),
				// v2.0.0 — Sales users can generate leads (territory-locked) and interact with them.
				// They cannot change salesperson, spend range, or demographic filters.
				'zdz_sales' => array(
					'can_generate_test',
					'can_generate_full',
					'can_sync_nutshell',
					'can_mark_contacted',
					'can_update_status',
					'can_forward_note',
					'can_view_notes',
					'view_batch_history',
					'view_lead_details',
					'view_contact_info',
					'view_pricing',
				),
				'zdz_operator' => array(
					'can_generate_test',
					'can_edit_filters',
					'can_choose_salesperson',
					'can_sync_nutshell',
					'can_mark_contacted',
					'can_update_status',
					'can_forward_note',
					'can_view_notes',
					'view_batch_history',
					'view_lead_details',
					'view_contact_info',
					'view_pricing',
				),
			),
			'users' => array(
				// Example: 'john_doe' => array( '!view_pricing' ),
				// '!' prefix revokes a feature for that specific user.
				// Without prefix, grants a feature even if their role doesn't have it.
			),
		);
	}

	/**
	 * Load the current permission configuration from wp_options.
	 *
	 * @return array { roles: array, users: array }
	 */
	public static function get_config() {
		$config = get_option( self::OPTION_KEY );
		if ( ! is_array( $config ) ) {
			$json = get_option( self::OPTION_KEY, '' );
			if ( is_string( $json ) && ! empty( $json ) ) {
				$config = json_decode( $json, true );
			}
		}
		if ( ! is_array( $config ) || empty( $config ) ) {
			$config = self::get_defaults();
		}
		// Ensure structure
		if ( ! isset( $config['roles'] ) || ! is_array( $config['roles'] ) ) {
			$config['roles'] = self::get_defaults()['roles'];
		}
		if ( ! isset( $config['users'] ) || ! is_array( $config['users'] ) ) {
			$config['users'] = array();
		}
		return $config;
	}

	/**
	 * Save the permission configuration to wp_options.
	 *
	 * @param array $config { roles: array, users: array }
	 * @return bool
	 */
	public static function save_config( $config ) {
		return update_option( self::OPTION_KEY, $config, false );
	}

	/**
	 * Get the list of features granted to a specific role.
	 *
	 * @param string $role Role slug (e.g. 'zdz_admin', 'zdz_operator').
	 * @return string[] Feature keys. Contains 'all' if role has full access.
	 */
	public static function get_role_features( $role ) {
		$config = self::get_config();
		if ( isset( $config['roles'][ $role ] ) && is_array( $config['roles'][ $role ] ) ) {
			return $config['roles'][ $role ];
		}
		return array();
	}

	/**
	 * Get username-specific overrides.
	 *
	 * @param string $username
	 * @return string[] Feature keys (with optional '!' prefix for revocation).
	 */
	public static function get_user_overrides( $username ) {
		$config = self::get_config();
		if ( isset( $config['users'][ $username ] ) && is_array( $config['users'][ $username ] ) ) {
			return $config['users'][ $username ];
		}
		return array();
	}

	/**
	 * Check if a specific user has a specific feature permission.
	 *
	 * Resolution order:
	 * 1. If user has manage_options (WP Admin), they get ALL features
	 *    UNLESS specifically revoked by username override.
	 * 2. Check username overrides: '!feature' revokes, 'feature' grants.
	 * 3. Check role features: 'all' grants everything, or check feature list.
	 *
	 * @param int|null    $user_id  WordPress user ID (null = current user).
	 * @param string      $feature  Feature key to check.
	 * @return bool
	 */
	public static function user_can( $feature, $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$username   = $user->user_login;
		$overrides  = self::get_user_overrides( $username );

		// Check username-level revocation first (highest priority)
		if ( in_array( '!' . $feature, $overrides, true ) ) {
			return false;
		}

		// Check username-level grant
		if ( in_array( $feature, $overrides, true ) ) {
			return true;
		}

		// WP Admin (manage_options) or ts_owner gets everything not explicitly revoked
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		// v1.6.0 — ts_owner role has full access (theme v2.3.0 super-admin role)
		if ( in_array( 'zdz_owner', (array) $user->roles, true ) ) {
			return true;
		}

		// Check each of the user's roles
		$roles = (array) $user->roles;
		foreach ( $roles as $role ) {
			$role_features = self::get_role_features( $role );
			// 'all' grants everything
			if ( in_array( 'all', $role_features, true ) ) {
				return true;
			}
			if ( in_array( $feature, $role_features, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convenience: check if CURRENT user has a feature.
	 *
	 * @param string $feature
	 * @return bool
	 */
	public static function current_user_can_feature( $feature ) {
		return self::user_can( $feature );
	}

	/**
	 * Get all features the current user has access to.
	 * Used to build the permission-aware JS config sent to the widget.
	 *
	 * @param int|null $user_id
	 * @return string[] List of granted feature keys.
	 */
	public static function get_user_features( $user_id = null ) {
		$all_keys = self::get_all_feature_keys();
		$granted  = array();
		foreach ( $all_keys as $key ) {
			if ( self::user_can( $key, $user_id ) ) {
				$granted[] = $key;
			}
		}
		return $granted;
	}

	/**
	 * Scrub pricing/dollar amounts from a text string.
	 * Used when view_pricing is revoked — prevents data leakage through
	 * purchase summaries, spend figures, etc.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function scrub_pricing( $text ) {
		if ( empty( $text ) ) {
			return $text;
		}
		// Replace $1,234.56 patterns with [HIDDEN]
		return preg_replace( '/\$[\d,]+(?:\.\d{1,2})?/', '[HIDDEN]', $text );
	}

	/**
	 * Scrub pricing from lead data array if user lacks view_pricing.
	 * Applied server-side before sending lead data to the widget.
	 *
	 * @param array    $lead   Lead data array.
	 * @param int|null $user_id
	 * @return array   Scrubbed lead data.
	 */
	public static function maybe_scrub_lead( $lead, $user_id = null ) {
		if ( self::user_can( 'view_pricing', $user_id ) ) {
			return $lead;
		}

		// Scrub purchase summary
		if ( isset( $lead['purchase_summary'] ) ) {
			$lead['purchase_summary'] = self::scrub_pricing( $lead['purchase_summary'] );
		}

		// Scrub purchase history JSON
		if ( isset( $lead['purchase_history'] ) ) {
			$history = json_decode( $lead['purchase_history'], true );
			if ( is_array( $history ) ) {
				foreach ( $history as &$item ) {
					if ( isset( $item['amount'] ) ) {
						$item['amount'] = 0;
						$item['amount_display'] = '[HIDDEN]';
					}
				}
				unset( $item );
				$lead['purchase_history'] = wp_json_encode( $history );
			}
		}

		return $lead;
	}

	/**
	 * Scrub contact info from lead data if user lacks view_contact_info.
	 *
	 * @param array    $lead
	 * @param int|null $user_id
	 * @return array
	 */
	public static function maybe_scrub_contact_info( $lead, $user_id = null ) {
		if ( self::user_can( 'view_contact_info', $user_id ) ) {
			return $lead;
		}

		if ( isset( $lead['email'] ) && ! empty( $lead['email'] ) ) {
			// Show partial: first char + domain
			$parts = explode( '@', $lead['email'] );
			if ( count( $parts ) === 2 ) {
				$lead['email'] = substr( $parts[0], 0, 1 ) . '***@' . $parts[1];
			} else {
				$lead['email'] = '***@hidden';
			}
		}
		if ( isset( $lead['phone'] ) && ! empty( $lead['phone'] ) ) {
			$lead['phone'] = '***-****';
		}

		return $lead;
	}
}
