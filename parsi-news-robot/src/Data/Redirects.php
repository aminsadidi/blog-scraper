<?php
/**
 * Addresses of deleted robot posts and where they should go now.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Data;

use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class Redirects {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pnr_redirects';
	}

	/**
	 * @param string $type category | home | gone.
	 */
	public static function add( $url, $type, $target_id = 0 ) {
		global $wpdb;
		$path = Util::normalize_path( $url );
		if ( '' === $path ) {
			return;
		}
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'path_hash'   => md5( $path ),
				'path'        => $path,
				'target_type' => $type,
				'target_id'   => (int) $target_id,
				'created_at'  => time(),
				'hits'        => 0,
				'last_hit'    => 0,
			)
		);
	}

	/**
	 * @return object|null
	 */
	public static function find( $url ) {
		global $wpdb;
		$path = Util::normalize_path( $url );
		if ( '' === $path ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE path_hash = %s', md5( $path ) ) ); // phpcs:ignore WordPress.DB
	}

	public static function hit( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET hits = hits + 1, last_hit = %d WHERE id = %d', time(), (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB
	}

	public static function prune( $days ) {
		global $wpdb;
		if ( $days > 0 ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %d', time() - (int) $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB
		}
	}
}
