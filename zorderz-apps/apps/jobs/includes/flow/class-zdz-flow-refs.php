<?php
/**
 * Zdz_Flow_Refs — the namespaced external-reference map for work items.
 *
 * Every external record a work item touches is keyed by (system, entity, external_id) — never a
 * bare document number (the estimate#5982 != invoice#5982 collision). The database carries two
 * guarantees:
 *   - PRIMARY KEY(work_item_id, system, entity)     — one external id per (item, system, entity).
 *   - UNIQUE(system, entity, external_id)           — one work item per external record. THIS is
 *     the double-writer / duplicate-container catch: a second minter for the same external record
 *     cannot store its ref, and put() resolves the DB winner and throws Zdz_Flow_Ref_Conflict
 *     carrying that winner's id — the retreat a mint-lock relies on.
 *
 * Namespaces are APP-REGISTERED, never hardcoded here. An app declares its {system, entity} pairs
 * via the `zdz_work_item_ref_namespaces` filter; put() refuses an unregistered namespace fail-loud.
 * The substrate ships EMPTY — it registers no namespace and names no provider.
 *
 * Promotion path: move this file to `zorderz/inc/` unchanged.
 *
 * @package Zorderz\Jobs\Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zdz_Flow_Ref_Conflict' ) ) {
	/**
	 * Thrown when put() is asked to bind (system, entity, external_id) to one work item but the
	 * UNIQUE index shows a DIFFERENT work item already owns it. Carries the winner's id so a
	 * lost-race writer can retreat (discard its empty item) and adopt the winner.
	 */
	class Zdz_Flow_Ref_Conflict extends \RuntimeException {

		/** @var string winning work_item_id (the item that already owns the external record). */
		protected $winner_id;
		/** @var string */
		protected $ns_system;
		/** @var string */
		protected $ns_entity;
		/** @var string */
		protected $ext_id;

		public function __construct( string $winner_id, string $system, string $entity, string $external_id ) {
			$this->winner_id = $winner_id;
			$this->ns_system = $system;
			$this->ns_entity = $entity;
			$this->ext_id    = $external_id;
			parent::__construct(
				sprintf(
					'Ref (%s/%s/%s) is already owned by work item %s.',
					$system,
					$entity,
					$external_id,
					$winner_id
				)
			);
		}

		/** The work_item_id that won the race and owns the external record. */
		public function winner(): string {
			return $this->winner_id;
		}

		/** @return array{system:string,entity:string,external_id:string} */
		public function ref(): array {
			return array(
				'system'      => $this->ns_system,
				'entity'      => $this->ns_entity,
				'external_id' => $this->ext_id,
			);
		}
	}
}

if ( ! class_exists( 'Zdz_Flow_Refs' ) ) {

	class Zdz_Flow_Refs {

		/**
		 * The registered {system, entity} namespaces, from the app-owned filter.
		 *
		 * Apps declare their namespaces (e.g. an estimate document ref, a CRM lead ref, a
		 * calendar appointment ref); the substrate hardcodes none. Accepts either a flat list of
		 * `system/entity` strings or a list of `['system'=>..., 'entity'=>...]` maps.
		 *
		 * @return array<string,array{system:string,entity:string}> keyed "system/entity".
		 */
		public static function namespaces(): array {
			$raw = apply_filters( 'zdz_work_item_ref_namespaces', array() );
			$out = array();
			if ( ! is_array( $raw ) ) {
				return $out;
			}
			foreach ( $raw as $ns ) {
				if ( is_string( $ns ) && strpos( $ns, '/' ) !== false ) {
					list( $sys, $ent ) = array_map( 'trim', explode( '/', $ns, 2 ) );
				} elseif ( is_array( $ns ) ) {
					$sys = (string) ( $ns['system'] ?? '' );
					$ent = (string) ( $ns['entity'] ?? '' );
				} else {
					continue;
				}
				$sys = sanitize_key( $sys );
				$ent = sanitize_key( $ent );
				if ( $sys !== '' && $ent !== '' ) {
					$out[ $sys . '/' . $ent ] = array(
						'system' => $sys,
						'entity' => $ent,
					);
				}
			}
			return $out;
		}

		/** Is this {system, entity} pair a registered namespace? */
		public static function is_registered( string $system, string $entity ): bool {
			$system = sanitize_key( $system );
			$entity = sanitize_key( $entity );
			return isset( self::namespaces()[ $system . '/' . $entity ] );
		}

		/**
		 * Idempotently record that $work_item_id owns the external record
		 * (system, entity, external_id). Returns true on first record AND on re-record (no-op).
		 *
		 * @param array $meta optional: external_version, last_pulled_at, last_pushed_at,
		 *                     sync_state, last_error.
		 * @return bool
		 *
		 * @throws \InvalidArgumentException if the {system, entity} namespace is unregistered
		 *                                   (fail-loud) or $work_item_id is not a ULID.
		 * @throws Zdz_Flow_Ref_Conflict     if a DIFFERENT work item already owns the external record.
		 */
		public static function put( string $work_item_id, string $system, string $entity, string $external_id, array $meta = array() ): bool {
			global $wpdb;

			$system = sanitize_key( $system );
			$entity = sanitize_key( $entity );

			if ( ! Zdz_Ulid::is_valid( $work_item_id ) ) {
				throw new \InvalidArgumentException( 'Zdz_Flow_Refs::put() requires a valid work item id.' );
			}
			if ( ! self::is_registered( $system, $entity ) ) {
				// Fail-loud: an unregistered namespace is a wiring bug, not a silent drop.
				throw new \InvalidArgumentException(
					sprintf( 'Unregistered ref namespace "%s/%s" — declare it via the zdz_work_item_ref_namespaces filter.', $system, $entity )
				);
			}
			$external_id = (string) $external_id;
			if ( $external_id === '' ) {
				throw new \InvalidArgumentException( 'Zdz_Flow_Refs::put() requires a non-empty external_id.' );
			}

			$table = Zdz_Flow_DB::refs();

			// 1) Does the external record already have an owner? (uses UNIQUE(system,entity,external_id))
			$owner = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT work_item_id FROM {$table} WHERE `system` = %s AND entity = %s AND external_id = %s",
					$system,
					$entity,
					$external_id
				)
			);
			if ( null !== $owner && (string) $owner !== $work_item_id ) {
				throw new Zdz_Flow_Ref_Conflict( (string) $owner, $system, $entity, $external_id );
			}

			// The mutable-metadata columns, built from ONLY the keys the caller actually supplied, so
			// an idempotent re-put with no meta never wipes an existing value (e.g. never downgrades a
			// synced ref back to 'pending'). $wpdb->insert/update map PHP null to SQL NULL and backtick
			// the reserved word `system` for us — unlike a %s placeholder, which coerces null to '' and
			// would break the nullable DATETIME columns under strict SQL mode.
			$meta_data = array();
			$meta_fmt  = array();
			if ( array_key_exists( 'external_version', $meta ) ) {
				$meta_data['external_version'] = ( null === $meta['external_version'] ) ? null : (string) $meta['external_version'];
				$meta_fmt[]                    = '%s';
			}
			if ( isset( $meta['sync_state'] ) ) {
				$meta_data['sync_state'] = sanitize_key( (string) $meta['sync_state'] );
				$meta_fmt[]              = '%s';
			}
			if ( array_key_exists( 'last_error', $meta ) ) {
				$meta_data['last_error'] = ( null === $meta['last_error'] ) ? null : (string) $meta['last_error'];
				$meta_fmt[]              = '%s';
			}
			if ( isset( $meta['last_pulled_at'] ) ) {
				$meta_data['last_pulled_at'] = (string) $meta['last_pulled_at'];
				$meta_fmt[]                  = '%s';
			}
			if ( isset( $meta['last_pushed_at'] ) ) {
				$meta_data['last_pushed_at'] = (string) $meta['last_pushed_at'];
				$meta_fmt[]                  = '%s';
			}

			$where     = array( 'work_item_id' => $work_item_id, 'system' => $system, 'entity' => $entity );
			$where_fmt = array( '%s', '%s', '%s' );

			if ( null !== $owner ) {
				// Already ours: refresh only the provided metadata (still a no-op success).
				if ( ! empty( $meta_data ) ) {
					$wpdb->update( $table, $meta_data, $where, $meta_fmt, $where_fmt );
				}
				return true;
			}

			// 2) Does THIS item already have a ref for (system, entity)? (the PRIMARY KEY)
			$cur_ext = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT external_id FROM {$table} WHERE work_item_id = %s AND `system` = %s AND entity = %s",
					$work_item_id,
					$system,
					$entity
				)
			);
			if ( null !== $cur_ext ) {
				// A move: same (item, system, entity), new external_id. We already proved the new
				// external_id has no other owner, so re-point in place (never REPLACE — REPLACE would
				// delete another item's UNIQUE row).
				$data = array_merge( array( 'external_id' => $external_id ), $meta_data );
				$fmt  = array_merge( array( '%s' ), $meta_fmt );
				$wpdb->update( $table, $data, $where, $fmt, $where_fmt );
				return true;
			}

			// 3) A brand-new PK row. $wpdb->insert maps null -> SQL NULL; the UNIQUE index is the
			//    ultimate arbiter of a concurrent race.
			$data = array(
				'work_item_id' => $work_item_id,
				'system'       => $system,
				'entity'       => $entity,
				'external_id'  => $external_id,
				'sync_state'   => isset( $meta['sync_state'] ) ? sanitize_key( (string) $meta['sync_state'] ) : 'pending',
			);
			$fmt = array( '%s', '%s', '%s', '%s', '%s' );
			if ( array_key_exists( 'external_version', $meta ) ) {
				$data['external_version'] = ( null === $meta['external_version'] ) ? null : (string) $meta['external_version'];
				$fmt[]                    = '%s';
			}
			if ( array_key_exists( 'last_error', $meta ) ) {
				$data['last_error'] = ( null === $meta['last_error'] ) ? null : (string) $meta['last_error'];
				$fmt[]              = '%s';
			}
			if ( isset( $meta['last_pulled_at'] ) ) {
				$data['last_pulled_at'] = (string) $meta['last_pulled_at'];
				$fmt[]                  = '%s';
			}
			if ( isset( $meta['last_pushed_at'] ) ) {
				$data['last_pushed_at'] = (string) $meta['last_pushed_at'];
				$fmt[]                  = '%s';
			}

			$ok = $wpdb->insert( $table, $data, $fmt );
			if ( ! $ok ) {
				// Lost a concurrent race (UNIQUE or PK). Re-resolve the DB winner.
				$owner2 = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT work_item_id FROM {$table} WHERE `system` = %s AND entity = %s AND external_id = %s",
						$system,
						$entity,
						$external_id
					)
				);
				if ( null !== $owner2 && (string) $owner2 !== $work_item_id ) {
					throw new Zdz_Flow_Ref_Conflict( (string) $owner2, $system, $entity, $external_id );
				}
				// Either it is ours now (concurrent same-item insert) or a PK move landed — success.
			}
			return true;
		}

		/**
		 * Reverse lookup: which work item owns (system, entity, external_id)?
		 *
		 * @return string|null work_item_id, or null. Deterministic (the UNIQUE index means at most
		 *                     one row; ORDER BY is defensive against a pre-UNIQUE legacy duplicate).
		 */
		public static function get( string $system, string $entity, string $external_id ): ?string {
			global $wpdb;
			$system = sanitize_key( $system );
			$entity = sanitize_key( $entity );
			$table  = Zdz_Flow_DB::refs();
			$id     = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT work_item_id FROM {$table}
					 WHERE `system` = %s AND entity = %s AND external_id = %s
					 ORDER BY work_item_id ASC LIMIT 1",
					$system,
					$entity,
					(string) $external_id
				)
			);
			return ( null === $id ) ? null : (string) $id;
		}

		/**
		 * Forward lookup: the refs a work item holds, optionally filtered to one system/entity.
		 *
		 * @return array<int,array<string,mixed>> ref rows (ARRAY_A).
		 */
		public static function for( string $work_item_id, ?string $system = null, ?string $entity = null ): array {
			global $wpdb;
			$table = Zdz_Flow_DB::refs();
			$sql   = "SELECT * FROM {$table} WHERE work_item_id = %s";
			$args  = array( $work_item_id );
			if ( null !== $system ) {
				$sql   .= ' AND `system` = %s';
				$args[] = sanitize_key( $system );
			}
			if ( null !== $entity ) {
				$sql   .= ' AND entity = %s';
				$args[] = sanitize_key( $entity );
			}
			$sql .= ' ORDER BY `system` ASC, entity ASC';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
			return is_array( $rows ) ? $rows : array();
		}
	}
}
