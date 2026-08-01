<?php
/**
 * ZIM_Channels
 *
 * Channel CRUD + membership + default-channel seeding.
 *
 * Channels are stored as rows in wp_zim_conversations with kind='channel'.
 * Membership is per-user rows in wp_zim_members referencing conversation_id.
 *
 * SEEDING CONTRACT (activation-time):
 *   - #announcements — admin-post-only, auto-joins ALL users with zdz_access_app.
 *   - #sales         — members: zdz_sales + admins.
 *   - #ops           — members: zdz_operator + admins.
 *   - #mfg           — members: zdz_mfg + admins.
 *   - #techs         — members: zdz_tech + admins.
 *
 * Idempotent: re-running create_default() skips channels that already exist.
 *
 * AUDIT: creation and membership mutations flow through ZDZ_Admin_Dashboard
 * when present (see class_exists guard). Audit integration is optional so the
 * plugin works standalone on theme v1.x.
 *
 * MEMBERSHIP HELPER:
 *   ZIM_Membership — lightweight companion class, kept in this file so the
 *   two always stay in sync. Used by AJAX gating in class-zim-dashboard.php
 *   and by the polling endpoint pattern required by the MEP spec.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Channels {

	/**
	 * Default channel seed list. Slug → role whitelist (empty = everyone).
	 *
	 * Admin-post-only flag (is_announcements) gates write access in
	 * ZIM_Messages::post().
	 */
	public static function default_seed_spec() {
		return array(
			array(
				'slug'             => 'announcements',
				'name'             => '#announcements',
				'description'      => 'Company-wide announcements. Admins post; everyone reads.',
				'is_announcements' => 1,
				'roles'            => array(), // everyone with zdz_access_app
			),
			array(
				'slug'        => 'sales',
				'name'        => '#sales',
				'description' => 'Sales team + admins.',
				'roles'       => array( 'zdz_sales', 'zdz_admin', 'zdz_owner', 'administrator' ),
			),
			array(
				'slug'        => 'ops',
				'name'        => '#ops',
				'description' => 'Operations and service coordination.',
				'roles'       => array( 'zdz_operator', 'zdz_admin', 'zdz_owner', 'administrator' ),
			),
			array(
				'slug'        => 'mfg',
				'name'        => '#mfg',
				'description' => 'Manufacturing and shop.',
				'roles'       => array( 'zdz_mfg', 'zdz_admin', 'zdz_owner', 'administrator' ),
			),
			array(
				'slug'        => 'techs',
				'name'        => '#techs',
				'description' => 'Field techs.',
				'roles'       => array( 'zdz_tech', 'zdz_admin', 'zdz_owner', 'administrator' ),
			),
		);
	}

	/**
	 * Seed the five default channels and auto-join users by role.
	 * Runs on plugin activation. Idempotent.
	 */
	public static function seed_defaults() {
		foreach ( self::default_seed_spec() as $spec ) {
			$existing = self::get_by_slug( $spec['slug'] );
			if ( $existing ) {
				$conversation_id = (int) $existing->id;
			} else {
				$conversation_id = self::create( array(
					'slug'             => $spec['slug'],
					'name'             => $spec['name'],
					'description'      => $spec['description'],
					'is_private'       => 0,
					'is_announcements' => ! empty( $spec['is_announcements'] ) ? 1 : 0,
					'created_by'       => 0, // system
				) );
				if ( ! $conversation_id ) {
					continue;
				}
			}

			// Auto-join users matching role whitelist (or everyone for announcements).
			$users = self::find_users_for_roles( $spec['roles'] );
			foreach ( $users as $user_id ) {
				self::add_member( $conversation_id, $user_id, 'member' );
			}
		}
	}

	/**
	 * Resolve the user pool for a role whitelist. Empty whitelist = all
	 * users with zdz_access_app.
	 *
	 * @param string[] $roles
	 * @return int[] user IDs
	 */
	private static function find_users_for_roles( array $roles ) {
		$args = array(
			'fields' => array( 'ID' ),
			'number' => -1,
		);
		if ( ! empty( $roles ) ) {
			$args['role__in'] = $roles;
		}
		$users = get_users( $args );
		$out = array();
		foreach ( $users as $u ) {
			if ( zim_user_has_access( (int) $u->ID ) ) {
				$out[] = (int) $u->ID;
			}
		}
		return $out;
	}

	/**
	 * Create a channel. Returns conversation_id or 0 on failure.
	 *
	 * @param array $args  slug, name, description, is_private, is_announcements, created_by
	 */
	public static function create( array $args ) {
		global $wpdb;

		$slug = sanitize_title( $args['slug'] ?? '' );
		$name = sanitize_text_field( $args['name'] ?? ( '#' . $slug ) );
		if ( '' === $slug ) {
			return 0;
		}
		if ( self::get_by_slug( $slug ) ) {
			return 0; // duplicate
		}

		$ok = $wpdb->insert(
			$wpdb->prefix . 'zim_conversations',
			array(
				'kind'             => 'channel',
				'slug'             => $slug,
				'name'             => $name,
				'description'      => sanitize_text_field( $args['description'] ?? '' ),
				'is_private'       => ! empty( $args['is_private'] ) ? 1 : 0,
				'is_announcements' => ! empty( $args['is_announcements'] ) ? 1 : 0,
				'created_by'       => (int) ( $args['created_by'] ?? 0 ),
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%s','%s','%s','%s','%d','%d','%d','%s' )
		);
		if ( false === $ok ) {
			return 0;
		}
		$conversation_id = (int) $wpdb->insert_id;

		// Creator is first admin-member.
		$creator = (int) ( $args['created_by'] ?? 0 );
		if ( $creator > 0 ) {
			self::add_member( $conversation_id, $creator, 'admin' );
		}

		self::audit( 'zim_channel_created', array(
			'conversation_id' => $conversation_id,
			'slug'            => $slug,
			'is_private'      => ! empty( $args['is_private'] ) ? 1 : 0,
		) );

		return $conversation_id;
	}

	public static function get_by_slug( $slug ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zim_conversations
			  WHERE kind = 'channel' AND slug = %s LIMIT 1",
			$slug
		) );
	}

	public static function get( $conversation_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}zim_conversations WHERE id = %d",
			(int) $conversation_id
		) );
	}

	/**
	 * List channels the user is a member of (for sidebar).
	 *
	 * @return array  [ [ 'id', 'slug', 'name', 'is_private', 'is_announcements', 'unread', 'role' ], ... ]
	 */
	public static function list_for_user( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.slug, c.name, c.is_private, c.is_announcements,
			        c.last_message_at, m.role, m.last_read_message_id
			   FROM {$wpdb->prefix}zim_conversations c
			   JOIN {$wpdb->prefix}zim_members m ON m.conversation_id = c.id
			  WHERE c.kind = 'channel' AND m.user_id = %d
			  ORDER BY c.is_announcements DESC, c.name ASC",
			$user_id
		), ARRAY_A );

		if ( ! $rows ) {
			return array();
		}

		// Compute unread counts in one pass.
		foreach ( $rows as &$r ) {
			$r['unread'] = self::unread_count(
				(int) $r['id'],
				(int) $r['last_read_message_id']
			);
			$r['id'] = (int) $r['id'];
			$r['is_private'] = (int) $r['is_private'];
			$r['is_announcements'] = (int) $r['is_announcements'];
		}
		return $rows;
	}

	/**
	 * Count non-deleted messages in a conversation newer than the cursor
	 * (by id — IDs are monotonic). Cheap: uses idx_conv_created.
	 */
	public static function unread_count( $conversation_id, $since_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}zim_messages
			  WHERE conversation_id = %d
			    AND id > %d
			    AND deleted_at IS NULL",
			(int) $conversation_id,
			(int) $since_id
		) );
	}

	/**
	 * Add a user to a channel. Idempotent — updates role if row exists.
	 */
	public static function add_member( $conversation_id, $user_id, $role = 'member' ) {
		global $wpdb;
		$conversation_id = (int) $conversation_id;
		$user_id         = (int) $user_id;
		$role            = in_array( $role, array( 'member', 'admin' ), true ) ? $role : 'member';

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, role FROM {$wpdb->prefix}zim_members
			  WHERE conversation_id = %d AND user_id = %d LIMIT 1",
			$conversation_id,
			$user_id
		) );

		if ( $existing ) {
			if ( $existing->role !== $role ) {
				$wpdb->update(
					$wpdb->prefix . 'zim_members',
					array( 'role' => $role ),
					array( 'id'   => (int) $existing->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
			return (int) $existing->id;
		}

		$wpdb->insert(
			$wpdb->prefix . 'zim_members',
			array(
				'conversation_id'      => $conversation_id,
				'user_id'              => $user_id,
				'role'                 => $role,
				'joined_at'            => current_time( 'mysql', true ),
				'last_read_message_id' => 0,
			),
			array( '%d','%d','%s','%s','%d' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function remove_member( $conversation_id, $user_id ) {
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'zim_members',
			array(
				'conversation_id' => (int) $conversation_id,
				'user_id'         => (int) $user_id,
			),
			array( '%d', '%d' )
		);
		self::audit( 'zim_channel_member_removed', array(
			'conversation_id' => (int) $conversation_id,
			'user_id'         => (int) $user_id,
		) );
	}

	public static function list_members( $conversation_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT m.user_id, m.role, m.joined_at, u.display_name, u.user_login
			   FROM {$wpdb->prefix}zim_members m
			   JOIN {$wpdb->users} u ON u.ID = m.user_id
			  WHERE m.conversation_id = %d
			  ORDER BY m.role DESC, u.display_name ASC",
			(int) $conversation_id
		), ARRAY_A );
	}

	/**
	 * Update a member's read cursor. Called on message view.
	 * Only moves forward — never regresses.
	 */
	public static function mark_read( $conversation_id, $user_id, $message_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}zim_members
			    SET last_read_message_id = GREATEST(last_read_message_id, %d)
			  WHERE conversation_id = %d AND user_id = %d",
			(int) $message_id,
			(int) $conversation_id,
			(int) $user_id
		) );
	}

	/**
	 * Optional audit log hook (no-op when theme's admin dashboard class is
	 * absent — keeps the plugin theme-version tolerant).
	 *
	 * Theme contract (as of Zorderz 2.13.1):
	 *   ZDZ_Admin_Dashboard::log_action( $user_id, $action_type, $detail, $app_id, $meta )
	 *   — class is in the GLOBAL namespace, not `\Zorderz\`.
	 */
	private static function audit( $slug, $meta ) {
		if ( ! class_exists( 'ZDZ_Admin_Dashboard' )
		     || ! method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			return;
		}
		$detail = self::describe_audit( $slug, $meta );
		ZDZ_Admin_Dashboard::log_action(
			get_current_user_id(),
			$slug,
			$detail,
			'zdz-internal-messaging',
			is_array( $meta ) ? $meta : array()
		);
	}

	/**
	 * Build a short human-readable detail string for the audit log,
	 * given a slug and meta. Kept deliberately terse — the `$meta` JSON
	 * holds the full context; this is just what a human skims in a list.
	 */
	private static function describe_audit( $slug, $meta ) {
		$meta = is_array( $meta ) ? $meta : array();
		switch ( $slug ) {
			case 'channel_created':
				return sprintf( 'Created channel #%s (id %d)',
					(string) ( $meta['slug'] ?? '' ),
					(int)    ( $meta['conversation_id'] ?? 0 )
				);
			case 'channel_member_added':
				return sprintf( 'Added user %d to conversation %d',
					(int) ( $meta['user_id'] ?? 0 ),
					(int) ( $meta['conversation_id'] ?? 0 )
				);
			case 'channel_member_removed':
				return sprintf( 'Removed user %d from conversation %d',
					(int) ( $meta['user_id'] ?? 0 ),
					(int) ( $meta['conversation_id'] ?? 0 )
				);
		}
		return (string) $slug;
	}
}

/**
 * ZIM_Membership — thin helper used by AJAX permission gates.
 *
 * Kept in the same file as ZIM_Channels because the two are conceptually
 * joined (channel membership IS DM membership — same table, different kind).
 */
class ZIM_Membership {

	/**
	 * Is the user a member of the conversation?
	 *
	 * Admins are implicitly members of all non-private channels, BUT this
	 * method returns the literal answer. Implicit access is layered on top
	 * by ZIM_Dashboard when handling admin-tier actions.
	 */
	public static function is_member( $user_id, $conversation_id ) {
		global $wpdb;
		$user_id         = (int) $user_id;
		$conversation_id = (int) $conversation_id;
		if ( $user_id <= 0 || $conversation_id <= 0 ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}zim_members
			  WHERE conversation_id = %d AND user_id = %d LIMIT 1",
			$conversation_id,
			$user_id
		) );
	}

	/**
	 * Is the user an admin in this conversation (channel)?
	 * Site-wide admins (zdz_admin / administrator / manage_options) are
	 * always considered channel admins — see Dashboard gate.
	 */
	public static function is_channel_admin( $user_id, $conversation_id ) {
		global $wpdb;
		$user_id         = (int) $user_id;
		$conversation_id = (int) $conversation_id;

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$u = get_userdata( $user_id );
		if ( $u && array_intersect( array( 'zdz_owner', 'zdz_admin' ), (array) $u->roles ) ) {
			return true;
		}

		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}zim_members
			  WHERE conversation_id = %d AND user_id = %d AND role = 'admin' LIMIT 1",
			$conversation_id,
			$user_id
		) );
	}
}
