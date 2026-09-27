<?php
/**
 * Records which homepage section each robot post was placed in; used for section quotas.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Data;

defined( 'ABSPATH' ) || exit;

class Placements {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pnr_placements';
	}

	public static function add( $post_id, $term_id, $source_id ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'post_id'   => (int) $post_id,
				'term_id'   => (int) $term_id,
				'source_id' => (int) $source_id,
				'placed_at' => time(),
			),
			array( '%d', '%d', '%d', '%d' )
		);
	}

	public static function count_since( $term_id, $since ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE term_id = %d AND placed_at >= %d', (int) $term_id, (int) $since ) ); // phpcs:ignore WordPress.DB
	}

	public static function prune( $days = 45 ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE placed_at < %d', time() - (int) $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB
	}
}
