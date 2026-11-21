<?php
/**
 * ZEST_Lists - what the widget's two lists hold: the open estimates and the past ones, each with its follow-up.
 *
 *   open      every estimate not yet sent to the customer, and every estimate not yet accepted whose follow-up still
 *             stands (asked, tried, answered or declined): an estimate that was sent to the customer comes back to the
 *             list when someone is asked to follow up on it, and stays there with its answer.
 *   history   the newest estimates.
 *
 * WHOSE. The admin tier sees all (ZEST_Access::is_admin_tier(): an administrator role, unless the platform's data
 * permissions say this person does not see other people's data). Anyone else sees their own (ZEST_Salesperson's rule:
 * created, named on it, or granted) and the estimates sent to them to follow up, less any an administrator removed
 * their access to.
 *
 * Each row carries its newest follow-up as ZEST_Followup_Ajax::view() words it, whether it has any follow-up history,
 * and whether a follow-up may be recorded on it; the reply carries what this person may do (`followups`). For an
 * account in Privacy View every row is built again from a list of fields (ZEST_Privacy_View::list_row()).
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZEST_Lists {

	/** A list's reply: { rows, followups }. $which: open | history. */
	public static function reply( int $uid, string $which ): array {
		$rows = 'open' === $which ? self::open_rows( $uid ) : self::rows_for( $uid, '1=1', 200, self::sent_to_me( $uid ) );
		$ids  = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row['id'];
		}
		$fu  = class_exists( 'ZEST_Followup_Ajax' ) ? ZEST_Followup_Ajax::for_list( $ids, $uid ) : array( 'map' => array(), 'any' => array(), 'meta' => null );
		$pv  = class_exists( 'ZEST_Privacy_View' ) && ZEST_Privacy_View::on();
		$out = array();
		foreach ( $rows as $row ) {
			$id      = (int) $row['id'];
			$latest  = $fu['map'][ $id ] ?? null;
			$history = ! empty( $fu['any'][ $id ] );
			if ( $pv ) {
				$out[] = ZEST_Privacy_View::list_row( $row, $latest, $history );
				continue;
			}
			$r                     = self::public_row( $row );
			$r['followup']         = $latest;
			$r['followup_history'] = $history;
			$r['can_follow']       = class_exists( 'ZEST_Followup_Ajax' ) && '' === ZEST_Followup_Ajax::not_open( $row );
			$r['declined']         = (bool) ( $latest['declined'] ?? false );
			$out[]                 = $r;
		}
		if ( $pv ) {
			return ZEST_Privacy_View::list_reply( $out );
		}
		$reply = array( 'rows' => $out );
		if ( is_array( $fu['meta'] ) ) {
			$reply['followups'] = $fu['meta'];
		}
		return $reply;
	}

	/** One estimate as a list shows it. */
	public static function public_row( array $row ): array {
		return array(
			'id'       => (int) $row['id'],
			'customer' => (string) $row['customer_name'],
			'number'   => (string) $row['billing_doc_num'],
			'status'   => (string) $row['status'],
			'created'  => (string) $row['created_at'],
			'items'    => (int) $row['item_count'],
		);
	}

	/** The estimates whose follow-up was sent to this person, less any an administrator removed their access to. */
	private static function sent_to_me( int $uid ): array {
		if ( ! class_exists( 'ZEST_Followup' ) || ZEST_Access::is_admin_tier( $uid ) ) {
			return array();   // the admin tier's lists are not narrowed to "mine", so there is nothing to add
		}
		$ids = array_map( 'intval', (array) ZEST_Followup::quiet( static function () use ( $uid ) {
			return ZEST_Followup::sent_to( $uid );
		}, array() ) );
		$off = class_exists( 'ZEST_Access_Requests' ) ? array_map( 'intval', (array) ZEST_Access_Requests::revoked_rows( $uid ) ) : array();
		return array_values( array_diff( $ids, $off ) );
	}

	/** The Open list's rows. */
	private static function open_rows( int $uid ): array {
		$where = "status IN ('created','stub','open')";
		$with  = class_exists( 'ZEST_Followup' ) ? array_map( 'intval', (array) ZEST_Followup::quiet( static function () {
			return ZEST_Followup::with_followup();
		}, array() ) ) : array();
		if ( $with ) {
			$where = '(' . $where . ' OR ( id IN (' . implode( ',', $with ) . ") AND doc_type = 'estimate' AND accepted_at IS NULL AND status NOT IN ('accepted','converted') ))";
		}
		return self::rows_for( $uid, $where, 300, self::sent_to_me( $uid ) );
	}

	/**
	 * This app's whole rows for a list, newest first. $where is one of this class's own fixed clauses (its only values
	 * are whole numbers). $also: estimates that are on this person's list whatever the row says (the ones sent to them
	 * to follow up), as whole numbers.
	 */
	private static function rows_for( int $uid, string $where, int $limit = 100, array $also = array() ): array {
		global $wpdb;
		$table = ZEST_DB::estimates_table();
		if ( ZEST_Access::is_admin_tier( $uid ) ) {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d", $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is ZEST_DB::estimates_table(); $where is one of this class's fixed clauses, its only values int-cast ids
		} else {
			// "mine" = created, named on it (salesperson column: a routed stub is its routed person's), or granted
			list( $mine, $args ) = ZEST_Salesperson::mine_sql( $uid );
			$also = array_values( array_filter( array_map( 'intval', $also ) ) );
			if ( $also ) {
				$mine = '(' . $mine . ' OR id IN (' . implode( ',', $also ) . '))';
			}
			$args[] = $limit;
			$sql    = $wpdb->prepare( "SELECT * FROM {$table} WHERE ({$where}) AND {$mine} ORDER BY created_at DESC, id DESC LIMIT %d", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is ZEST_DB::estimates_table(); $where is one of this class's fixed clauses; $mine is ZEST_Salesperson::mine_sql()'s fragment of placeholders and int-cast ids
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared just above, in each branch
		return is_array( $rows ) ? $rows : array();
	}
}
