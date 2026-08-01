<?php
/**
 * ZG_Scores — high-score persistence and leaderboard queries for the Game app.
 *
 * DB table: wp_zg_game_scores (one row per user — their single personal best).
 * All methods are static for direct use from the REST callbacks.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZG_Scores {

	/** Fully-qualified scores table name. */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zg_game_scores';
	}

	/**
	 * Submit a score for a user. Keeps only one row per user — their highest.
	 *
	 * @param int    $user_id
	 * @param int    $score
	 * @param int    $level
	 * @param string $pattern Pattern key active when the score was submitted.
	 * @return array { id: int, is_personal_best: bool, rank: int }
	 */
	public static function submit_score( int $user_id, int $score, int $level = 1, string $pattern = 'wall' ): array {
		global $wpdb;
		$table = self::table();

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, score FROM {$table} WHERE user_id = %d LIMIT 1", $user_id ),
			ARRAY_A
		);

		$is_pb = false;

		if ( $existing ) {
			if ( $score > (int) $existing['score'] ) {
				// New personal best — update the existing row in place.
				$wpdb->update(
					$table,
					array(
						'score'      => $score,
						'level'      => $level,
						'pattern'    => $pattern,
						'created_at' => current_time( 'mysql' ),
					),
					array( 'id' => (int) $existing['id'] ),
					array( '%d', '%d', '%s', '%s' ),
					array( '%d' )
				);
				$is_pb = true;
			}
			// Not a new best → keep the existing higher score untouched.
			$insert_id = (int) $existing['id'];
		} else {
			// First score for this user.
			$wpdb->insert(
				$table,
				array(
					'user_id'    => $user_id,
					'score'      => $score,
					'level'      => $level,
					'pattern'    => $pattern,
					'created_at' => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%d', '%s', '%s' )
			);
			$insert_id = (int) $wpdb->insert_id;
			$is_pb     = true;
		}

		// Global rank = how many other users scored strictly higher, + 1.
		$best_for_rank = $score > (int) ( $existing['score'] ?? 0 ) ? $score : (int) ( $existing['score'] ?? $score );
		$rank          = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) + 1 FROM {$table} WHERE score > %d AND user_id != %d",
				$best_for_rank,
				$user_id
			)
		);

		return array(
			'id'               => $insert_id,
			'is_personal_best' => $is_pb,
			'rank'             => $rank,
		);
	}

	/**
	 * Global leaderboard — one row per user (their best score).
	 *
	 * @param int $limit
	 * @return array
	 */
	public static function get_leaderboard( int $limit = 20 ): array {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT gs.user_id, gs.score, gs.level, gs.created_at
				 FROM {$table} gs
				 INNER JOIN (
				     SELECT user_id, MAX(score) AS best
				     FROM {$table}
				     GROUP BY user_id
				 ) top ON gs.user_id = top.user_id AND gs.score = top.best
				 GROUP BY gs.user_id
				 ORDER BY gs.score DESC, gs.created_at ASC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		// Hydrate display names — first name only for privacy.
		$out = array();
		foreach ( $rows as $i => $row ) {
			$user = get_userdata( (int) $row['user_id'] );
			$name = 'Player';
			if ( $user ) {
				$name = $user->first_name;
				if ( empty( $name ) ) {
					$name = explode( ' ', (string) $user->display_name )[0];
				}
			}
			$out[] = array(
				'rank'    => $i + 1,
				'user_id' => (int) $row['user_id'],
				'name'    => $name,
				'score'   => (int) $row['score'],
				'level'   => (int) $row['level'],
				'date'    => $row['created_at'],
			);
		}

		return $out;
	}

	/**
	 * A specific user's single best score (empty array if none yet).
	 *
	 * @param int $user_id
	 * @return array
	 */
	public static function get_user_scores( int $user_id ): array {
		global $wpdb;
		$table = self::table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT score, level, pattern, created_at FROM {$table} WHERE user_id = %d LIMIT 1",
				$user_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return array();
		}

		return array(
			array(
				'score'   => (int) $row['score'],
				'level'   => (int) $row['level'],
				'pattern' => $row['pattern'],
				'date'    => $row['created_at'],
			),
		);
	}
}
