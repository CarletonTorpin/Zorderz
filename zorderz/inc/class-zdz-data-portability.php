<?php
/**
 * ZDZ_Data_Portability - Company Data Export / Import.
 *
 * One place a business owner can take ALL of their Zorderz company data off one
 * install and restore it on another (a fresh WordPress + Zorderz). It is the
 * portability / backup / migration tool, and it doubles as the honest record of
 * what moved (a manifest of per-area counts, so a migration can be scored).
 *
 * DESIGN (informed by WordPress core's Personal Data Exporter pattern and by the
 * hard lessons of DB-level migration tools):
 *
 *   - GO THROUGH WORDPRESS, NOT RAW SQL. Options are read with get_option (already
 *     unserialized) and written with update_option (WordPress re-serializes), so
 *     the serialized-data corruption that breaks naive find-and-replace never
 *     happens. Custom-table rows move as plain associative arrays.
 *   - PREFIX-DRIVEN DISCOVERY. Every Zorderz table and option carries a known
 *     prefix (zdz_, zana_, zest_, zkv_, zim_, zsch_, zstock_, zcc_, zic_, zg_,
 *     zsv_, zl_, zjob_, plus legacy ts*). We discover them at runtime via SHOW
 *     TABLES and an option query, so new apps are covered automatically. Both
 *     lists are filterable for extension.
 *   - PRESERVE PRIMARY KEYS on import into an EMPTY install. Because the target is
 *     fresh, re-inserting rows with their original ids keeps every intra-Zorderz
 *     foreign key (estimate to line, item to scheme, row to user) valid with no
 *     remapping. Users are restored with their original ids too, which keeps those
 *     references valid; the fresh install's default admin may be replaced by the
 *     imported owner (documented, and a dry-run is offered first).
 *   - SECRETS NEVER LEAVE. Connection credentials (Poe key, FreshBooks and
 *     Nutshell tokens, OAuth) are excluded by an option-name denylist, by
 *     scrubbing secret-named columns out of table rows, and by skipping
 *     credential/ephemeral tables. The new install re-connects its own services.
 *
 * SCOPE: import assumes a FRESH target (empty Zorderz tables). Re-import is
 * idempotent (REPLACE by primary key). Merging into a populated install is out of
 * scope for v1 and would need id remapping per the notes above.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Data_Portability {

	/** Bundle schema version. Bump when the bundle shape changes incompatibly. */
	const FORMAT_VERSION = 1;

	/** Capability required to export or import. */
	const CAP = 'manage_options';

	/** Admin page slug (under Tools). */
	const SLUG = 'zdz-data-portability';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_zdz_data_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_zdz_data_import', array( __CLASS__, 'handle_import' ) );
	}

	/* ---------------------------------------------------------------------- */
	/* Configuration (all filterable)                                          */
	/* ---------------------------------------------------------------------- */

	/** Bare-name prefixes (after $wpdb->prefix) that identify a Zorderz table. */
	private static function table_prefixes(): array {
		return (array) apply_filters( 'zdz_export_table_prefixes', array(
			'zdz_', 'zana_', 'zstock_', 'zg_', 'zcc_', 'zkv_', 'zjob_', 'zim_',
			'zic_', 'zsch_', 'zest_', 'zsv_', 'zl_', 'zlg_', 'zprep_', 'zsketch_',
			'tsec_', 'tsl_', 'tsa_', 'tscc_',
		) );
	}

	/** Option-name prefixes that identify a Zorderz option. */
	private static function option_prefixes(): array {
		return (array) apply_filters( 'zdz_export_option_prefixes', array(
			'zdz_', 'zana_', 'zstock_', 'zg_', 'zcc_', 'zkv_', 'zjob_', 'zim_',
			'zic_', 'zsch_', 'zest_', 'zsv_', 'zorderz_',
			'tsec_', 'tsl_', 'tsa_', 'tscc_', 'ts_core_', 'ts_surveys_',
		) );
	}

	/**
	 * Portable WordPress-core settings that SHOULD travel with the business (so a
	 * fresh install lands with the right identity and behaviour, not WP defaults):
	 * site title, tagline, timezone, date/time/week, and the permalink structure.
	 * Deliberately EXCLUDES install-specific options (siteurl, home, upload paths):
	 * carrying those would point the new site at the old one and break it. The import
	 * only applies names on this list, so the uploaded file cannot smuggle others in.
	 */
	private static function portable_wp_settings(): array {
		return (array) apply_filters( 'zdz_export_wp_settings', array(
			'blogname', 'blogdescription', 'timezone_string', 'gmt_offset',
			'date_format', 'time_format', 'start_of_week', 'permalink_structure',
			'WPLANG', 'blog_charset',
		) );
	}

	/**
	 * Name prefixes that identify a Zorderz-owned custom post type or taxonomy.
	 * These live in WordPress' shared posts/terms tables (not our zXX_ tables), so
	 * they are discovered by object name via get_post_types()/get_taxonomies() and
	 * matched here - e.g. zrcpt_receipt (Receipts), zdz_bug_report, zdz_item_subtype.
	 */
	private static function object_prefixes(): array {
		return (array) apply_filters( 'zdz_export_object_prefixes', array(
			'zdz_', 'zrcpt_', 'zana_', 'zest_', 'zkv_', 'zim_', 'zsch_',
			'zstock_', 'zcc_', 'zic_', 'zg_', 'zl_', 'zsv_', 'zjob_', 'zprep_', 'zorderz_',
		) );
	}

	private static function is_zorderz_object( string $name ): bool {
		foreach ( self::object_prefixes() as $p ) {
			if ( 0 === strpos( $name, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/** True if the option name carries a Zorderz prefix (mirrors the export discovery rule). */
	private static function is_zorderz_option( string $name ): bool {
		foreach ( self::option_prefixes() as $p ) {
			if ( 0 === strpos( $name, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Case-insensitive substrings that mark an option name or a table column as a
	 * secret. Anything matching is NEVER exported. Business data never contains
	 * these tokens; connection credentials always do.
	 */
	private static function secret_markers(): array {
		return (array) apply_filters( 'zdz_export_secret_markers', array(
			'api_key', 'apikey', 'client_secret', 'client_id', 'access_token',
			'refresh_token', 'oauth', '_secret', 'secret_', 'password', '_pass',
			'private_key', 'webhook_secret', 'signing', 'bearer',
		) );
	}

	/**
	 * Name ENDINGS that mark a secret. Checked as suffixes (not substrings) so that
	 * 'token_count' or 'monkey' never match, but 'zdz_core_review_bridge_key' and
	 * 'zsch_graph_token' do. Closes the class of miss where a credential's option name
	 * carries no obvious marker word (the review_bridge_key export leak, Aug 2026).
	 */
	private static function secret_suffixes(): array {
		return (array) apply_filters( 'zdz_export_secret_suffixes', array(
			'_key', '_token', '_secret', '_pass', '_password',
		) );
	}

	/**
	 * Authoritative EXACT option names known to hold secrets, self-declaring per module.
	 * A new credential option is excluded the moment it is added to its owning module's
	 * list. Core's list is the single source of truth in ZDZ_Core_Settings::secret_fields();
	 * other modules add theirs via the filter. This is the allowlist-style defense the
	 * security audit asked for: exclusion by declaration, not by guessing at names.
	 */
	private static function secret_option_names(): array {
		$names = array();
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'secret_fields' ) ) {
			foreach ( (array) ZDZ_Core_Settings::secret_fields() as $f ) {
				$names[] = 'zdz_core_' . $f;
			}
		}
		$names = array_merge( $names, array(
			'zsch_graph_token',   // Microsoft Graph OAuth bundle (access/refresh token nested in value)
			'zsch_google_token',  // Google OAuth bundle, if connected
			'zkv_poe_api_key',    // Knowledge-vault Poe key (also encrypted at rest)
			'zic_fb_oauth',       // FreshBooks OAuth store, if present
		) );
		$names = (array) apply_filters( 'zdz_export_secret_option_names', $names );
		return array_map( 'strtolower', array_values( array_unique( array_filter( $names ) ) ) );
	}

	/**
	 * Nested array KEYS that mark a secret leaf inside an option value, redacted wherever
	 * they appear at any depth. Catches a credential nested in a value even when the option
	 * NAME is not obviously a secret (e.g. an OAuth token bundle stored under one option).
	 */
	private static function secret_value_keys(): array {
		return (array) apply_filters( 'zdz_export_secret_value_keys', array(
			'access_token', 'refresh_token', 'client_secret', 'api_key', 'apikey',
			'private_key', 'webhook_secret', 'bearer', 'oauth_token', 'password',
			'client_id', 'secret',
		) );
	}

	/**
	 * Table name suffixes to skip: ephemeral queues, regenerable caches, and
	 * credential stores. These are not company data and must not travel.
	 */
	private static function skip_table_suffixes(): array {
		return (array) apply_filters( 'zdz_export_skip_table_suffixes', array(
			'_jobs', '_sync', '_notification_queue', '_push_subscriptions',
			'calendar_accounts', '_cache', '_transients',
		) );
	}

	/** User meta keys to skip on export (session tokens, raw role caps, transients). */
	private static function skip_user_meta(): array {
		global $wpdb;
		return array(
			'session_tokens',
			$wpdb->prefix . 'capabilities', // roles are exported separately and re-applied
			$wpdb->prefix . 'user_level',
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Discovery                                                               */
	/* ---------------------------------------------------------------------- */

	/** All Zorderz custom tables present on this install (minus the skip list). */
	public static function discover_tables(): array {
		global $wpdb;
		$out    = array();
		$prefix = $wpdb->prefix;
		$names  = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! $names ) {
			return $out;
		}
		$tprefixes = self::table_prefixes();
		$skips     = self::skip_table_suffixes();
		foreach ( $names as $full ) {
			if ( 0 !== strpos( $full, $prefix ) ) {
				continue; // only this install's tables
			}
			$bare = substr( $full, strlen( $prefix ) ); // e.g. zdz_items
			$hit  = false;
			foreach ( $tprefixes as $tp ) {
				if ( 0 === strpos( $bare, $tp ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				continue;
			}
			$skip = false;
			foreach ( $skips as $s ) {
				if ( strlen( $bare ) >= strlen( $s ) && substr( $bare, -strlen( $s ) ) === $s ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}
			$out[] = $full;
		}
		sort( $out );
		return (array) apply_filters( 'zdz_export_tables', $out );
	}

	/**
	 * True if the given option/column name looks like a secret. Three layers, any one is
	 * sufficient: (1) an authoritative exact-name list declared by the owning module,
	 * (2) case-insensitive substring markers, (3) name-ending suffixes. Layered so a new
	 * credential is excluded by default rather than leaking until someone notices. Used by
	 * BOTH export (never emit) and import (never write), so hardening here fixes both.
	 */
	private static function is_secret( string $name ): bool {
		$lc = strtolower( $name );
		if ( in_array( $lc, self::secret_option_names(), true ) ) {
			return true;
		}
		foreach ( self::secret_markers() as $m ) {
			if ( '' !== $m && false !== strpos( $lc, $m ) ) {
				return true;
			}
		}
		foreach ( self::secret_suffixes() as $s ) {
			$len = strlen( $s );
			if ( $len && strlen( $lc ) >= $len && substr( $lc, -$len ) === $s ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recursively null any array leaf whose KEY looks like a secret, so a credential nested
	 * inside an option value never travels even if the option name itself was not caught.
	 * $hit is set true (by reference) when anything is redacted, for the excluded manifest.
	 *
	 * @param mixed $value
	 * @param bool  $hit
	 * @return mixed
	 */
	private static function redact_secret_values( $value, bool &$hit ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$markers = self::secret_value_keys();
		$out     = array();
		foreach ( $value as $k => $v ) {
			$lc        = strtolower( (string) $k );
			$is_secret = false;
			foreach ( $markers as $m ) {
				if ( '' !== $m && false !== strpos( $lc, $m ) ) {
					$is_secret = true;
					break;
				}
			}
			if ( $is_secret ) {
				$out[ $k ] = null;
				$hit       = true;
			} else {
				$out[ $k ] = self::redact_secret_values( $v, $hit );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------- */
	/* Collection (export)                                                     */
	/* ---------------------------------------------------------------------- */

	/** Skip-secret (and any extra skip keys) + unserialize a get_*_meta() result. */
	private static function filter_meta( array $meta_raw, array $skip = array() ): array {
		$meta = array();
		foreach ( $meta_raw as $k => $vals ) {
			if ( ( $skip && in_array( $k, $skip, true ) ) || self::is_secret( (string) $k ) ) {
				continue;
			}
			$meta[ $k ] = array_map( 'maybe_unserialize', (array) $vals );
		}
		return $meta;
	}

	/** Zorderz options, unserialized, secrets removed. */
	private static function collect_options( array &$excluded ): array {
		global $wpdb;
		$out    = array();
		$where  = array();
		$params = array();
		foreach ( self::option_prefixes() as $p ) {
			$where[]  = 'option_name LIKE %s';
			$params[] = $wpdb->esc_like( $p ) . '%';
		}
		$sql  = "SELECT option_name, option_value FROM {$wpdb->options} WHERE " . implode( ' OR ', $where );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$name = $row['option_name'];
			if ( self::is_secret( $name ) ) {
				$excluded[] = $name;
				continue;
			}
			// Even for a non-secret name, deep-redact any secret-keyed leaves in the value
			// (e.g. an OAuth token bundle) so a credential nested in a value never travels.
			$val = maybe_unserialize( $row['option_value'] );
			$hit = false;
			$val = self::redact_secret_values( $val, $hit );
			if ( $hit ) {
				$excluded[] = $name . ' (secret value redacted)';
			}
			$out[ $name ] = $val;
		}
		return $out;
	}

	/** Column names for a table (for scrubbing and for import filtering). */
	private static function columns_for( string $table ): array {
		global $wpdb;
		$cols = $wpdb->get_col( 'SHOW COLUMNS FROM `' . str_replace( '`', '', $table ) . '`', 0 );
		return $cols ? $cols : array();
	}

	/** Rows for every Zorderz table, with secret-named columns nulled out. */
	private static function collect_tables( array &$excluded ): array {
		global $wpdb;
		$out = array();
		foreach ( self::discover_tables() as $table ) {
			$bare    = substr( $table, strlen( $wpdb->prefix ) );
			$secret_cols = array();
			foreach ( self::columns_for( $table ) as $c ) {
				if ( self::is_secret( $c ) ) {
					$secret_cols[] = $c;
				}
			}
			$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
			if ( $secret_cols ) {
				foreach ( $rows as &$r ) {
					foreach ( $secret_cols as $sc ) {
						if ( array_key_exists( $sc, $r ) ) {
							$r[ $sc ] = null;
						}
					}
				}
				unset( $r );
				$excluded[] = $bare . ' (columns: ' . implode( ', ', $secret_cols ) . ')';
			}
			$out[ $bare ] = $rows ? $rows : array();
		}
		return $out;
	}

	/**
	 * WordPress users with roles + meta (the Party roster). Core fields are read
	 * straight from the users table via $wpdb, because get_users() does NOT populate
	 * user_pass on every host (verified empty on WP Engine) - and the password hash
	 * is what lets logins survive the move. The bundle therefore contains password
	 * hashes: it is admin-only, but treat the file as sensitive.
	 */
	private static function collect_users(): array {
		global $wpdb;
		$out  = array();
		$skip = self::skip_user_meta();
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->users}", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$id       = (int) $row['ID'];
			$wpu      = new WP_User( $id );
			$meta = self::filter_meta( (array) get_user_meta( $id ), $skip );
			$out[] = array(
				'ID'                  => $id,
				'user_login'          => $row['user_login'],
				'user_pass'           => $row['user_pass'], // hash straight from the table; lets logins survive the move
				'user_nicename'       => $row['user_nicename'],
				'user_email'          => $row['user_email'],
				'user_url'            => $row['user_url'],
				'user_registered'     => $row['user_registered'],
				'user_activation_key' => isset( $row['user_activation_key'] ) ? $row['user_activation_key'] : '',
				'user_status'         => isset( $row['user_status'] ) ? $row['user_status'] : 0,
				'display_name'        => $row['display_name'],
				'roles'               => array_values( (array) $wpu->roles ),
				'meta'                => $meta,
			);
		}
		return $out;
	}

	/** Attachment posts + key meta, so logo/document references resolve once the uploads folder is copied. */
	private static function collect_attachments(): array {
		global $wpdb;
		$out  = array();
		$rows = $wpdb->get_results(
			"SELECT ID, post_author, post_date, post_date_gmt, post_title, post_excerpt, post_status, post_name, post_parent, post_mime_type, guid, post_content FROM {$wpdb->posts} WHERE post_type = 'attachment'",
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$row['meta'] = array(
				'_wp_attached_file'       => get_post_meta( (int) $row['ID'], '_wp_attached_file', true ),
				'_wp_attachment_metadata' => get_post_meta( (int) $row['ID'], '_wp_attachment_metadata', true ),
				'_wp_attachment_image_alt' => get_post_meta( (int) $row['ID'], '_wp_attachment_image_alt', true ),
			);
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Map of uploads-relative path => absolute path for every file that backs an
	 * attachment (the original plus each generated thumbnail size). The export adds
	 * these to the zip so media resolves on the target with no manual copy of
	 * wp-content/uploads.
	 */
	private static function attachment_files(): array {
		$files = array();
		$up    = wp_get_upload_dir();
		$base  = trailingslashit( $up['basedir'] );
		$ids   = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
		foreach ( (array) $ids as $id ) {
			$rel = get_post_meta( (int) $id, '_wp_attached_file', true );
			if ( ! $rel ) {
				continue;
			}
			if ( is_file( $base . $rel ) ) {
				$files[ $rel ] = $base . $rel;
			}
			$meta = wp_get_attachment_metadata( (int) $id );
			if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
				continue;
			}
			$dir = dirname( $rel );
			$dir = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir ); // '2026/08/' or '' for a root file
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$srel = $dir . $size['file'];
				if ( is_file( $base . $srel ) ) {
					$files[ $srel ] = $base . $srel;
				}
			}
		}
		return $files;
	}

	/** Zorderz-owned custom post types: posts + all postmeta, preserving ids. */
	private static function collect_cpts(): array {
		$out   = array();
		$types = get_post_types( array( '_builtin' => false ), 'names' );
		foreach ( (array) $types as $pt ) {
			if ( 'attachment' === $pt || ! self::is_zorderz_object( $pt ) ) {
				continue; // attachments are handled separately; skip non-Zorderz types
			}
			$rows  = array();
			$posts = get_posts( array(
				'post_type'   => $pt,
				'post_status' => 'any',
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			) );
			foreach ( (array) $posts as $p ) {
				$meta = self::filter_meta( (array) get_post_meta( $p->ID ) );
				$rows[] = array(
					'ID'            => (int) $p->ID,
					'post_author'   => $p->post_author,
					'post_date'     => $p->post_date,
					'post_date_gmt' => $p->post_date_gmt,
					'post_title'    => $p->post_title,
					'post_content'  => $p->post_content,
					'post_excerpt'  => $p->post_excerpt,
					'post_status'   => $p->post_status,
					'post_name'     => $p->post_name,
					'post_parent'   => (int) $p->post_parent,
					'menu_order'    => (int) $p->menu_order,
					'post_type'     => $p->post_type,
					'meta'          => $meta,
				);
			}
			$out[ $pt ] = $rows;
		}
		return $out;
	}

	/** Zorderz-owned taxonomies: terms + term meta (e.g. item subtype scope/priority/type). */
	private static function collect_taxonomies(): array {
		$out   = array();
		$taxes = get_taxonomies( array( '_builtin' => false ), 'names' );
		foreach ( (array) $taxes as $tax ) {
			if ( ! self::is_zorderz_object( $tax ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$rows = array();
			foreach ( (array) $terms as $t ) {
				$meta = self::filter_meta( (array) get_term_meta( $t->term_id ) );
				$rows[] = array(
					'term_id'     => (int) $t->term_id,
					'name'        => $t->name,
					'slug'        => $t->slug,
					'description' => $t->description,
					'parent'      => (int) $t->parent,
					'meta'        => $meta,
				);
			}
			$out[ $tax ] = $rows;
		}
		return $out;
	}

	/** Assemble the full export bundle with a manifest of counts. */
	public static function build_bundle(): array {
		global $wpdb;
		$excluded = array();
		$options  = self::collect_options( $excluded );
		$tables   = self::collect_tables( $excluded );
		$users    = self::collect_users();
		$attach   = self::collect_attachments();
		$cpts     = self::collect_cpts();
		$taxes    = self::collect_taxonomies();

		$wp_settings = array();
		foreach ( self::portable_wp_settings() as $name ) {
			$val = get_option( $name, null );
			if ( null !== $val ) {
				$wp_settings[ $name ] = $val;
			}
		}

		$table_counts = array();
		foreach ( $tables as $name => $rows ) {
			$table_counts[ $name ] = count( $rows );
		}
		$cpt_counts = array();
		foreach ( $cpts as $pt => $rows ) {
			$cpt_counts[ $pt ] = count( $rows );
		}
		$tax_counts = array();
		foreach ( $taxes as $tx => $rows ) {
			$tax_counts[ $tx ] = count( $rows );
		}

		return array(
			'zorderz_data_bundle' => array(
				'format_version'  => self::FORMAT_VERSION,
				'generated_at'    => gmdate( 'c' ),
				'source_url'      => home_url(),
				'zorderz_version' => defined( 'ZDZ_THEME_VER_FLOOR' ) ? ZDZ_THEME_VER_FLOOR : '',
				'wp_version'      => get_bloginfo( 'version' ),
				'table_prefix'    => $wpdb->prefix,
			),
			'manifest' => array(
				'options'     => count( $options ),
				'tables'      => $table_counts,
				'users'       => count( $users ),
				'attachments' => count( $attach ),
				'post_types'  => $cpt_counts,
				'taxonomies'  => $tax_counts,
				'wp_settings' => count( $wp_settings ),
			),
			'options'     => $options,
			'wp_settings' => $wp_settings,
			'tables'      => $tables,
			'users'       => $users,
			'attachments' => $attach,
			'post_types'  => $cpts,
			'taxonomies'  => $taxes,
			'excluded'    => array(
				'reason' => 'Connection credentials and ephemeral/queue data are never exported. Re-connect services on the new install.',
				'items'  => array_values( array_unique( $excluded ) ),
			),
		);
	}

	/** Cheap counts for the admin preview (no full data build). */
	public static function manifest_counts(): array {
		global $wpdb;
		$opt = 0;
		$where = array();
		$params = array();
		foreach ( self::option_prefixes() as $p ) {
			$where[]  = 'option_name LIKE %s';
			$params[] = $wpdb->esc_like( $p ) . '%';
		}
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE " . implode( ' OR ', $where ), $params ) );
		foreach ( (array) $names as $n ) {
			if ( ! self::is_secret( $n ) ) {
				$opt++;
			}
		}
		$tables = array();
		foreach ( self::discover_tables() as $t ) {
			$bare            = substr( $t, strlen( $wpdb->prefix ) );
			$tables[ $bare ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" );
		}
		$users  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		$attach = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
		$cpts = array();
		foreach ( get_post_types( array( '_builtin' => false ), 'names' ) as $pt ) {
			if ( 'attachment' === $pt || ! self::is_zorderz_object( $pt ) ) {
				continue;
			}
			$cpts[ $pt ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'", $pt ) );
		}
		$taxes = array();
		foreach ( get_taxonomies( array( '_builtin' => false ), 'names' ) as $tx ) {
			if ( ! self::is_zorderz_object( $tx ) ) {
				continue;
			}
			$c            = wp_count_terms( array( 'taxonomy' => $tx, 'hide_empty' => false ) );
			$taxes[ $tx ] = is_wp_error( $c ) ? 0 : (int) $c;
		}
		$media       = count( self::attachment_files() );
		$wp_settings = 0;
		foreach ( self::portable_wp_settings() as $name ) {
			if ( null !== get_option( $name, null ) ) {
				$wp_settings++;
			}
		}
		return array( 'options' => $opt, 'wp_settings' => $wp_settings, 'tables' => $tables, 'users' => $users, 'attachments' => $attach, 'media_files' => $media, 'post_types' => $cpts, 'taxonomies' => $taxes );
	}

	/* ---------------------------------------------------------------------- */
	/* Import                                                                  */
	/* ---------------------------------------------------------------------- */

	/** A zip bundle begins with the local-file-header magic, or has a .zip name. */
	private static function is_zip( string $tmp, string $orig ): bool {
		if ( preg_match( '/\.zip$/i', $orig ) ) {
			return true;
		}
		$sig = '';
		$fh  = @fopen( $tmp, 'rb' );
		if ( $fh ) {
			$sig = (string) fread( $fh, 4 );
			fclose( $fh );
		}
		return "PK\x03\x04" === $sig;
	}

	/**
	 * True if a bundle-relative uploads path is safe to write under wp-content/uploads:
	 * non-empty, no '..' path segment, and not absolute or a drive path. PURE (no WordPress
	 * calls) so it is unit-testable in isolation, and public so the test suite can pin it.
	 * The executable-type rejection (wp_check_filetype) is applied separately by the caller;
	 * BOTH guards must survive every change to the extraction loop (see the security audit).
	 *
	 * @param string $rel Path relative to the uploads base.
	 * @return bool
	 */
	public static function is_safe_upload_relpath( string $rel ): bool {
		$rel = str_replace( '\\', '/', $rel );
		if ( '' === $rel ) {
			return false;
		}
		if ( in_array( '..', explode( '/', $rel ), true ) ) {
			return false;
		}
		if ( preg_match( '#^([A-Za-z]:)?/#', $rel ) ) {
			return false;
		}
		return true;
	}

	/** Write every uploads/* entry from the zip into wp-content/uploads. Returns the file count. */
	private static function extract_uploads( ZipArchive $zip ): int {
		$up   = wp_get_upload_dir();
		$base = trailingslashit( $up['basedir'] );
		$n    = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( 0 !== strpos( $name, 'uploads/' ) || '/' === substr( $name, -1 ) ) {
				continue;
			}
			$rel = str_replace( '\\', '/', substr( $name, strlen( 'uploads/' ) ) );
			// Reject empty, path-traversal segments, and absolute/drive paths: only ever write
			// under uploads. Logic is in the pure, unit-tested is_safe_upload_relpath() helper.
			if ( ! self::is_safe_upload_relpath( $rel ) ) {
				continue;
			}
			// Only write file types WordPress itself permits as uploads, never executables
			// (.php/.phtml/.phar) or .htaccess, so a crafted zip cannot drop a webshell.
			$ft = wp_check_filetype( $rel );
			if ( empty( $ft['ext'] ) || empty( $ft['type'] ) ) {
				continue;
			}
			$dest = $base . $rel;
			wp_mkdir_p( dirname( $dest ) );
			$data = $zip->getFromIndex( $i );
			if ( false !== $data && false !== file_put_contents( $dest, $data ) ) {
				$n++;
			}
		}
		return $n;
	}

	/** Count uploads/* file entries in the zip (for the dry-run preview). */
	private static function count_upload_entries( ZipArchive $zip ): int {
		$n = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( 0 === strpos( $name, 'uploads/' ) && '/' !== substr( $name, -1 ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Restore a bundle. Returns a result manifest (restored counts) so the import
	 * can be scored against the export. Idempotent: REPLACE by primary key.
	 *
	 * @param array $bundle Decoded bundle.
	 * @param array $opts   ['dry_run' => bool]
	 */
	public static function import_bundle( array $bundle, array $opts = array() ): array {
		global $wpdb;
		$dry = ! empty( $opts['dry_run'] );
		$res = array(
			'dry_run'     => $dry,
			'options'     => 0,
			'wp_settings' => 0,
			'tables'      => array(),
			'users'       => 0,
			'attachments' => 0,
			'post_types'  => array(),
			'taxonomies'  => array(),
			'skipped'     => array(),
			'errors'      => array(),
		);

		// Options. Restricted to Zorderz-owned names (the same rule the export uses), so a
		// crafted bundle cannot set core options like siteurl, home, or default_role here.
		// Portable WordPress-core settings travel in their own allowlisted section below.
		if ( ! empty( $bundle['options'] ) && is_array( $bundle['options'] ) ) {
			foreach ( $bundle['options'] as $name => $value ) {
				if ( self::is_secret( (string) $name ) ) {
					$res['skipped'][] = "option:{$name} (secret)";
					continue;
				}
				if ( ! self::is_zorderz_option( (string) $name ) ) {
					$res['skipped'][] = "option:{$name} (not a Zorderz option)";
					continue;
				}
				if ( ! $dry ) {
					update_option( $name, $value );
				}
				$res['options']++;
			}
		}

		// Portable WordPress settings (title, tagline, timezone, permalinks, ...).
		// Only allowlisted names are applied, so an uploaded bundle cannot set siteurl/home.
		if ( ! empty( $bundle['wp_settings'] ) && is_array( $bundle['wp_settings'] ) ) {
			$allow = array_flip( self::portable_wp_settings() );
			foreach ( $bundle['wp_settings'] as $name => $value ) {
				if ( ! isset( $allow[ $name ] ) ) {
					$res['skipped'][] = "wp_setting:{$name} (not portable)";
					continue;
				}
				if ( ! $dry ) {
					update_option( $name, $value );
				}
				$res['wp_settings']++;
			}
			// Make the permalink change take effect now, so pretty URLs work without a
			// manual visit to Settings > Permalinks. The in-memory structure was loaded at
			// request start (the fresh install's default), so set it before flushing or the
			// rules regenerate for the old structure and every pretty URL 404s.
			if ( ! $dry && array_key_exists( 'permalink_structure', $bundle['wp_settings'] ) && isset( $GLOBALS['wp_rewrite'] ) ) {
				$GLOBALS['wp_rewrite']->set_permalink_structure( (string) $bundle['wp_settings']['permalink_structure'] );
				flush_rewrite_rules( false );
			}
		}

		// Attachments (posts + meta), preserving ids.
		if ( ! empty( $bundle['attachments'] ) && is_array( $bundle['attachments'] ) ) {
			$post_cols = self::columns_for( $wpdb->posts );
			foreach ( $bundle['attachments'] as $att ) {
				if ( empty( $att['ID'] ) ) {
					continue;
				}
				$meta = isset( $att['meta'] ) ? $att['meta'] : array();
				unset( $att['meta'] );
				$att['post_type'] = 'attachment';
				$row = array_intersect_key( $att, array_flip( $post_cols ) );
				if ( ! $dry ) {
					$wpdb->replace( $wpdb->posts, $row );
					$id = (int) $att['ID'];
					foreach ( (array) $meta as $mk => $mv ) {
						if ( '' !== $mv && null !== $mv ) {
							update_post_meta( $id, $mk, $mv );
						}
					}
				}
				$res['attachments']++;
			}
		}

		// Custom tables. Only tables that exist on the target; preserve ids via REPLACE.
		if ( ! empty( $bundle['tables'] ) && is_array( $bundle['tables'] ) ) {
			foreach ( $bundle['tables'] as $bare => $rows ) {
				$bare = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $bare ); // defensive: table name from an uploaded file
				if ( '' === $bare ) {
					continue;
				}
				$full = $wpdb->prefix . $bare;
				if ( self::is_secret( (string) $bare ) ) {
					$res['skipped'][] = "table:{$bare} (secret)";
					continue;
				}
				$cols = self::columns_for( $full );
				if ( empty( $cols ) ) {
					$res['skipped'][] = "table:{$bare} (not present on target)";
					continue;
				}
				$n = 0;
				foreach ( (array) $rows as $row ) {
					$clean = array_intersect_key( (array) $row, array_flip( $cols ) );
					if ( empty( $clean ) ) {
						continue;
					}
					if ( ! $dry ) {
						$ok = $wpdb->replace( $full, $clean );
						if ( false === $ok ) {
							$res['errors'][] = "row in {$bare}: " . $wpdb->last_error;
							continue;
						}
					}
					$n++;
				}
				$res['tables'][ $bare ] = $n;
			}
		}

		// Users, preserving ids (keeps every user-id foreign key valid on a fresh install).
		if ( ! empty( $bundle['users'] ) && is_array( $bundle['users'] ) ) {
			$user_cols = self::columns_for( $wpdb->users );
			foreach ( $bundle['users'] as $u ) {
				$roles = isset( $u['roles'] ) ? (array) $u['roles'] : array();
				$meta  = isset( $u['meta'] ) ? (array) $u['meta'] : array();
				$core  = array_intersect_key( $u, array_flip( $user_cols ) );
				if ( empty( $core['ID'] ) || empty( $core['user_login'] ) ) {
					continue;
				}
				if ( ! $dry ) {
					// Never write an empty password (which would lock the account out).
					// Keep the existing hash if the row already exists (protects the
					// admin running the import), else set a strong random one to reset.
					if ( empty( $core['user_pass'] ) ) {
						$existing = $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", (int) $core['ID'] ) );
						$core['user_pass'] = $existing ? $existing : wp_hash_password( wp_generate_password( 24, true, true ) );
					}
					$ok = $wpdb->replace( $wpdb->users, $core );
					if ( false === $ok ) {
						$res['errors'][] = 'user ' . $u['user_login'] . ': ' . $wpdb->last_error;
						continue;
					}
					$id = (int) $core['ID'];
					clean_user_cache( $id ); // REPLACE bypassed the object cache; drop the stale entry (e.g. the admin we just overwrote)
					foreach ( $meta as $mk => $vals ) {
						if ( self::is_secret( (string) $mk ) ) {
							continue;
						}
						delete_user_meta( $id, $mk );
						foreach ( (array) $vals as $v ) {
							add_user_meta( $id, $mk, $v );
						}
					}
					// Re-apply roles through the API so capabilities meta is correct for this prefix.
					$user = new WP_User( $id );
					$user->set_role( '' );
					foreach ( $roles as $r ) {
						$user->add_role( $r );
					}
					clean_user_cache( $id );
				}
				$res['users']++;
			}
		}

		// Custom post types (posts + postmeta), preserving ids into the fresh install.
		if ( ! empty( $bundle['post_types'] ) && is_array( $bundle['post_types'] ) ) {
			$post_cols = self::columns_for( $wpdb->posts );
			foreach ( $bundle['post_types'] as $pt => $rows ) {
				$n = 0;
				foreach ( (array) $rows as $row ) {
					$meta = isset( $row['meta'] ) ? (array) $row['meta'] : array();
					unset( $row['meta'] );
					$core = array_intersect_key( (array) $row, array_flip( $post_cols ) );
					if ( empty( $core['ID'] ) ) {
						continue;
					}
					if ( ! $dry ) {
						$ok = $wpdb->replace( $wpdb->posts, $core );
						if ( false === $ok ) {
							$res['errors'][] = "post in {$pt}: " . $wpdb->last_error;
							continue;
						}
						$id = (int) $core['ID'];
						foreach ( $meta as $mk => $vals ) {
							if ( self::is_secret( (string) $mk ) ) {
								continue;
							}
							delete_post_meta( $id, $mk );
							foreach ( (array) $vals as $v ) {
								add_post_meta( $id, $mk, $v );
							}
						}
					}
					$n++;
				}
				$res['post_types'][ $pt ] = $n;
			}
		}

		// Taxonomy terms + term meta, via the term API (safe on the shared terms tables).
		if ( ! empty( $bundle['taxonomies'] ) && is_array( $bundle['taxonomies'] ) ) {
			foreach ( $bundle['taxonomies'] as $tax => $terms ) {
				if ( ! $dry && ! taxonomy_exists( $tax ) ) {
					$res['skipped'][] = "taxonomy:{$tax} (not registered on target)";
					continue;
				}
				$n = 0;
				foreach ( (array) $terms as $t ) {
					if ( empty( $t['slug'] ) ) {
						continue;
					}
					if ( ! $dry ) {
						$existing = get_term_by( 'slug', $t['slug'], $tax );
						if ( $existing ) {
							$term_id = (int) $existing->term_id;
							wp_update_term( $term_id, $tax, array( 'name' => (string) $t['name'], 'description' => (string) ( isset( $t['description'] ) ? $t['description'] : '' ) ) );
						} else {
							$ins = wp_insert_term( (string) $t['name'], $tax, array( 'slug' => (string) $t['slug'], 'description' => (string) ( isset( $t['description'] ) ? $t['description'] : '' ) ) );
							if ( is_wp_error( $ins ) ) {
								$res['errors'][] = "term {$t['slug']}: " . $ins->get_error_message();
								continue;
							}
							$term_id = (int) $ins['term_id'];
						}
						foreach ( (array) ( isset( $t['meta'] ) ? $t['meta'] : array() ) as $mk => $vals ) {
							if ( self::is_secret( (string) $mk ) ) {
								continue;
							}
							delete_term_meta( $term_id, $mk );
							foreach ( (array) $vals as $v ) {
								add_term_meta( $term_id, $mk, $v );
							}
						}
					}
					$n++;
				}
				$res['taxonomies'][ $tax ] = $n;
			}
		}

		return $res;
	}

	/* ---------------------------------------------------------------------- */
	/* Admin UI (Tools -> Zorderz Data)                                        */
	/* ---------------------------------------------------------------------- */

	public static function admin_menu() {
		add_management_page(
			'Zorderz Data Portability',
			'Zorderz Data',
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Forbidden', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'zdz_data_export' );
		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '512M' );

		$bundle = self::build_bundle();
		$json   = wp_json_encode( $bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$host   = sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) );
		$stamp  = gmdate( 'Ymd-His' );
		$files  = self::attachment_files();

		// With media present, ship one .zip carrying the JSON plus the uploads files, so the
		// import resolves logos/photos with no separate copy of wp-content/uploads. Without
		// media (or without ZipArchive) fall back to the plain .json bundle.
		if ( $files && class_exists( 'ZipArchive' ) ) {
			$tmp = wp_tempnam( 'zorderz-data-zip' );
			$zip = new ZipArchive();
			if ( true === $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
				$zip->addFromString( 'zorderz-data.json', $json );
				foreach ( $files as $rel => $abs ) {
					$zip->addFile( $abs, 'uploads/' . $rel );
				}
				$zip->close();
				nocache_headers();
				header( 'Content-Type: application/zip' );
				header( 'Content-Disposition: attachment; filename="zorderz-data-' . $host . '-' . $stamp . '.zip"' );
				header( 'Content-Length: ' . filesize( $tmp ) );
				readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				@unlink( $tmp );
				exit;
			}
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="zorderz-data-' . $host . '-' . $stamp . '.json"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function handle_import() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Forbidden', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'zdz_data_import' );
		@set_time_limit( 0 );
		@ini_set( 'memory_limit', '512M' );

		$acting = get_current_user_id();
		$notice = array( 'type' => 'error', 'msg' => 'No file uploaded.' );

		if ( ! empty( $_FILES['zdz_bundle']['tmp_name'] ) && is_uploaded_file( $_FILES['zdz_bundle']['tmp_name'] ) ) {
			$tmp    = $_FILES['zdz_bundle']['tmp_name'];
			$orig   = isset( $_FILES['zdz_bundle']['name'] ) ? sanitize_file_name( (string) $_FILES['zdz_bundle']['name'] ) : '';
			$dry    = ! empty( $_POST['dry_run'] );
			$bundle = null;
			$media  = 0;

			if ( self::is_zip( $tmp, $orig ) ) {
				if ( ! class_exists( 'ZipArchive' ) ) {
					$notice = array( 'type' => 'error', 'msg' => 'This bundle is a .zip but ZipArchive is unavailable on this server. Enable the PHP zip extension, or use a .json bundle.' );
				} else {
					$zip = new ZipArchive();
					if ( true === $zip->open( $tmp ) ) {
						$bundle = json_decode( (string) $zip->getFromName( 'zorderz-data.json' ), true );
						if ( ! is_array( $bundle ) || empty( $bundle['zorderz_data_bundle'] ) ) {
							$notice = array( 'type' => 'error', 'msg' => 'The .zip does not contain a valid zorderz-data.json bundle.' );
							$bundle = null;
						} elseif ( $dry ) {
							$media = self::count_upload_entries( $zip );
						} else {
							$media = self::extract_uploads( $zip ); // only after the bundle validates
						}
						$zip->close();
					} else {
						$notice = array( 'type' => 'error', 'msg' => 'Could not open the .zip bundle.' );
					}
				}
			} else {
				$bundle = json_decode( (string) file_get_contents( $tmp ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}

			if ( null !== $bundle ) {
				if ( ! is_array( $bundle ) || empty( $bundle['zorderz_data_bundle'] ) ) {
					$notice = array( 'type' => 'error', 'msg' => 'That file is not a valid Zorderz data bundle.' );
				} else {
					$result                = self::import_bundle( $bundle, array( 'dry_run' => $dry ) );
					$result['media_files'] = $media;
					set_transient( 'zdz_dp_result_' . $acting, $result, 300 );
					$notice = array( 'type' => 'success', 'msg' => $dry ? 'Dry run complete (nothing written).' : 'Import complete.' );

					// A real import may have replaced the acting admin's user row with the
					// imported owner, invalidating the current auth cookie. Re-issue it for
					// the same id so the session continues instead of bouncing the operator
					// to a login screen mid-migration.
					if ( ! $dry && $acting ) {
						wp_set_auth_cookie( $acting, false, is_ssl() );
					}
				}
			}
		}

		set_transient( 'zdz_dp_notice_' . $acting, $notice, 300 );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$counts = self::manifest_counts();
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=zdz_data_export' ), 'zdz_data_export' );
		$uid     = get_current_user_id();
		$notice  = get_transient( 'zdz_dp_notice_' . $uid );
		$result  = get_transient( 'zdz_dp_result_' . $uid );
		delete_transient( 'zdz_dp_notice_' . $uid );
		delete_transient( 'zdz_dp_result_' . $uid );

		$total_tables = array_sum( $counts['tables'] );
		echo '<div class="wrap"><h1>Zorderz Data Portability</h1>';
		echo '<p>Export all of this business\'s Zorderz data to one portable file, then restore it onto a fresh install. The bundle carries settings, catalog, roster, estimates and invoices, media files (logos and photos), and the WordPress site title, tagline, timezone and permalink structure, so the new install lands ready to use. Connection credentials (Poe, FreshBooks, Nutshell, calendar OAuth) are never exported for security; re-connect them on the new install. The bundle includes user password hashes so logins carry over, so treat the downloaded file as sensitive.</p>';

		if ( $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' is-dismissible"><p>' . esc_html( $notice['msg'] ) . '</p></div>';
		}
		if ( is_array( $result ) ) {
			echo '<div class="notice notice-info"><p><strong>' . ( $result['dry_run'] ? 'Would restore' : 'Restored' ) . ':</strong> '
				. (int) $result['options'] . ' options, '
				. (int) ( isset( $result['wp_settings'] ) ? $result['wp_settings'] : 0 ) . ' site settings, '
				. array_sum( $result['tables'] ) . ' table rows, '
				. (int) $result['users'] . ' users, ' . (int) $result['attachments'] . ' attachments, '
				. (int) ( isset( $result['media_files'] ) ? $result['media_files'] : 0 ) . ' media files, '
				. array_sum( isset( $result['post_types'] ) ? $result['post_types'] : array() ) . ' post-type records, '
				. array_sum( isset( $result['taxonomies'] ) ? $result['taxonomies'] : array() ) . ' taxonomy terms.';
			if ( ! empty( $result['errors'] ) ) {
				echo ' <span style="color:#a00">' . count( $result['errors'] ) . ' error(s).</span>';
			}
			echo '</p></div>';
		}

		// Export card.
		echo '<h2 class="title">Export</h2>';
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Data area</th><th style="text-align:right">Records</th></tr></thead><tbody>';
		echo '<tr><td>Settings and profile (options)</td><td style="text-align:right">' . (int) $counts['options'] . '</td></tr>';
		echo '<tr><td>WordPress site settings (title, timezone, permalinks, ...)</td><td style="text-align:right">' . (int) ( isset( $counts['wp_settings'] ) ? $counts['wp_settings'] : 0 ) . '</td></tr>';
		echo '<tr><td>Users (roster)</td><td style="text-align:right">' . (int) $counts['users'] . '</td></tr>';
		echo '<tr><td>Media / attachments</td><td style="text-align:right">' . (int) $counts['attachments'] . '</td></tr>';
		echo '<tr><td>Media files (originals + thumbnails, in the zip)</td><td style="text-align:right">' . (int) ( isset( $counts['media_files'] ) ? $counts['media_files'] : 0 ) . '</td></tr>';
		foreach ( ( isset( $counts['post_types'] ) ? $counts['post_types'] : array() ) as $pt => $n ) {
			echo '<tr><td>Post type <code>' . esc_html( $pt ) . '</code></td><td style="text-align:right">' . (int) $n . '</td></tr>';
		}
		foreach ( ( isset( $counts['taxonomies'] ) ? $counts['taxonomies'] : array() ) as $tx => $n ) {
			echo '<tr><td>Taxonomy <code>' . esc_html( $tx ) . '</code> (terms)</td><td style="text-align:right">' . (int) $n . '</td></tr>';
		}
		foreach ( $counts['tables'] as $bare => $n ) {
			echo '<tr><td><code>' . esc_html( $bare ) . '</code></td><td style="text-align:right">' . (int) $n . '</td></tr>';
		}
		echo '<tr><td><strong>Custom-table rows (total)</strong></td><td style="text-align:right"><strong>' . (int) $total_tables . '</strong></td></tr>';
		echo '</tbody></table>';
		echo '<p style="margin-top:12px"><a class="button button-primary button-hero" href="' . esc_url( $export_url ) . '">Download data bundle</a> <span class="description">(.zip when media is present, otherwise .json)</span></p>';

		// Import card.
		echo '<hr><h2 class="title">Import</h2>';
		echo '<p><strong>Import onto a FRESH install.</strong> Records are restored with their original ids so all internal references stay valid, and media files, the site title/tagline/timezone and the permalink structure are applied automatically. If the imported owner replaces the account you are using, your session is kept alive so you stay logged in. Run a dry run first to preview counts. Accepts a .zip bundle (with media) or a .json bundle.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		wp_nonce_field( 'zdz_data_import' );
		echo '<input type="hidden" name="action" value="zdz_data_import">';
		echo '<p><input type="file" name="zdz_bundle" accept=".zip,.json,application/zip,application/json" required></p>';
		echo '<p><label><input type="checkbox" name="dry_run" value="1" checked> Dry run (preview counts, write nothing)</label></p>';
		echo '<p><button type="submit" class="button button-secondary">Import bundle</button></p>';
		echo '</form>';
		echo '</div>';
	}
}
