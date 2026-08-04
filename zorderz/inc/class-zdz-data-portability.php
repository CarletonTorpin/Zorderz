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

	/** True if the given option/column name looks like a secret. */
	private static function is_secret( string $name ): bool {
		$lc = strtolower( $name );
		foreach ( self::secret_markers() as $m ) {
			if ( false !== strpos( $lc, $m ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------- */
	/* Collection (export)                                                     */
	/* ---------------------------------------------------------------------- */

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
			$out[ $name ] = maybe_unserialize( $row['option_value'] );
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

	/** WordPress users with roles + meta (the Party roster). Includes pass hash. */
	private static function collect_users(): array {
		$out  = array();
		$skip = self::skip_user_meta();
		$users = get_users( array( 'fields' => 'all' ) );
		foreach ( $users as $u ) {
			$meta_raw = get_user_meta( $u->ID );
			$meta     = array();
			foreach ( (array) $meta_raw as $k => $vals ) {
				if ( in_array( $k, $skip, true ) || self::is_secret( $k ) ) {
					continue;
				}
				// get_user_meta returns each value as an array of (usually one) serialized strings.
				$meta[ $k ] = array_map( 'maybe_unserialize', (array) $vals );
			}
			$out[] = array(
				'ID'              => (int) $u->ID,
				'user_login'      => $u->user_login,
				'user_email'      => $u->user_email,
				'user_pass'       => $u->user_pass, // already-hashed; lets logins survive the move
				'user_nicename'   => $u->user_nicename,
				'user_url'        => $u->user_url,
				'display_name'    => $u->display_name,
				'user_registered' => $u->user_registered,
				'roles'           => array_values( (array) $u->roles ),
				'meta'            => $meta,
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

	/** Assemble the full export bundle with a manifest of counts. */
	public static function build_bundle(): array {
		global $wpdb;
		$excluded = array();
		$options  = self::collect_options( $excluded );
		$tables   = self::collect_tables( $excluded );
		$users    = self::collect_users();
		$attach   = self::collect_attachments();

		$table_counts = array();
		foreach ( $tables as $name => $rows ) {
			$table_counts[ $name ] = count( $rows );
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
			),
			'options'     => $options,
			'tables'      => $tables,
			'users'       => $users,
			'attachments' => $attach,
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
		return array( 'options' => $opt, 'tables' => $tables, 'users' => $users, 'attachments' => $attach );
	}

	/* ---------------------------------------------------------------------- */
	/* Import                                                                  */
	/* ---------------------------------------------------------------------- */

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
			'tables'      => array(),
			'users'       => 0,
			'attachments' => 0,
			'skipped'     => array(),
			'errors'      => array(),
		);

		// Options.
		if ( ! empty( $bundle['options'] ) && is_array( $bundle['options'] ) ) {
			foreach ( $bundle['options'] as $name => $value ) {
				if ( self::is_secret( (string) $name ) ) {
					$res['skipped'][] = "option:{$name} (secret)";
					continue;
				}
				if ( ! $dry ) {
					update_option( $name, $value );
				}
				$res['options']++;
			}
		}

		// Attachments (posts + meta), preserving ids.
		if ( ! empty( $bundle['attachments'] ) && is_array( $bundle['attachments'] ) ) {
			$post_cols = self::columns_for( $wpdb->posts );
			foreach ( $bundle['attachments'] as $att ) {
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
		$fname  = 'zorderz-data-' . sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Ymd-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
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

		$notice = array( 'type' => 'error', 'msg' => 'No file uploaded.' );
		if ( ! empty( $_FILES['zdz_bundle']['tmp_name'] ) && is_uploaded_file( $_FILES['zdz_bundle']['tmp_name'] ) ) {
			$raw    = file_get_contents( $_FILES['zdz_bundle']['tmp_name'] ); // phpcs:ignore
			$bundle = json_decode( (string) $raw, true );
			if ( ! is_array( $bundle ) || empty( $bundle['zorderz_data_bundle'] ) ) {
				$notice = array( 'type' => 'error', 'msg' => 'That file is not a valid Zorderz data bundle.' );
			} else {
				$dry    = ! empty( $_POST['dry_run'] );
				$result = self::import_bundle( $bundle, array( 'dry_run' => $dry ) );
				set_transient( 'zdz_dp_result_' . get_current_user_id(), $result, 300 );
				$notice = array( 'type' => 'success', 'msg' => $dry ? 'Dry run complete (nothing written).' : 'Import complete.' );
			}
		}
		set_transient( 'zdz_dp_notice_' . get_current_user_id(), $notice, 300 );
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
		echo '<p>Export all of this business\'s Zorderz data to one portable file, or restore that file onto a fresh install. Connection credentials (Poe, FreshBooks, Nutshell, calendar OAuth) are never exported for security; re-connect them on the new install.</p>';

		if ( $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' is-dismissible"><p>' . esc_html( $notice['msg'] ) . '</p></div>';
		}
		if ( is_array( $result ) ) {
			echo '<div class="notice notice-info"><p><strong>' . ( $result['dry_run'] ? 'Would restore' : 'Restored' ) . ':</strong> '
				. (int) $result['options'] . ' options, ' . array_sum( $result['tables'] ) . ' table rows, '
				. (int) $result['users'] . ' users, ' . (int) $result['attachments'] . ' attachments.';
			if ( ! empty( $result['errors'] ) ) {
				echo ' <span style="color:#a00">' . count( $result['errors'] ) . ' error(s).</span>';
			}
			echo '</p></div>';
		}

		// Export card.
		echo '<h2 class="title">Export</h2>';
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Data area</th><th style="text-align:right">Records</th></tr></thead><tbody>';
		echo '<tr><td>Settings and profile (options)</td><td style="text-align:right">' . (int) $counts['options'] . '</td></tr>';
		echo '<tr><td>Users (roster)</td><td style="text-align:right">' . (int) $counts['users'] . '</td></tr>';
		echo '<tr><td>Media / attachments</td><td style="text-align:right">' . (int) $counts['attachments'] . '</td></tr>';
		foreach ( $counts['tables'] as $bare => $n ) {
			echo '<tr><td><code>' . esc_html( $bare ) . '</code></td><td style="text-align:right">' . (int) $n . '</td></tr>';
		}
		echo '<tr><td><strong>Custom-table rows (total)</strong></td><td style="text-align:right"><strong>' . (int) $total_tables . '</strong></td></tr>';
		echo '</tbody></table>';
		echo '<p style="margin-top:12px"><a class="button button-primary button-hero" href="' . esc_url( $export_url ) . '">Download data bundle (.json)</a></p>';

		// Import card.
		echo '<hr><h2 class="title">Import</h2>';
		echo '<p><strong>Import onto a FRESH install.</strong> Records are restored with their original ids so all internal references stay valid; the default admin account may be replaced by the imported owner, so log in with your existing Zorderz credentials afterward. Run a dry run first to preview counts. Copy <code>wp-content/uploads</code> over separately so media files resolve.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		wp_nonce_field( 'zdz_data_import' );
		echo '<input type="hidden" name="action" value="zdz_data_import">';
		echo '<p><input type="file" name="zdz_bundle" accept="application/json,.json" required></p>';
		echo '<p><label><input type="checkbox" name="dry_run" value="1" checked> Dry run (preview counts, write nothing)</label></p>';
		echo '<p><button type="submit" class="button button-secondary">Import bundle</button></p>';
		echo '</form>';
		echo '</div>';
	}
}
