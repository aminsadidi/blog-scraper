<?php
/**
 * Event log stored in its own table (keeps wp_options small).
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Data;

defined( 'ABSPATH' ) || exit;

class Log {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pnr_log';
	}

	/**
	 * @param string $level info | success | warning | error.
	 */
	public static function add( $level, $message, $source_id = 0 ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at' => time(),
				'level'      => substr( sanitize_key( $level ), 0, 10 ),
				'source_id'  => (int) $source_id,
				'message'    => \ParsiNewsRobot\Support\Util::db_safe( wp_strip_all_tags( (string) $message ), self::table(), 'message' ),
			),
			array( '%d', '%s', '%d', '%s' )
		);
	}

	public static function info( $message, $source_id = 0 ) {
		self::add( 'info', $message, $source_id );
	}

	public static function success( $message, $source_id = 0 ) {
		self::add( 'success', $message, $source_id );
	}

	public static function warning( $message, $source_id = 0 ) {
		self::add( 'warning', $message, $source_id );
	}

	public static function error( $message, $source_id = 0 ) {
		self::add( 'error', $message, $source_id );
	}

	/**
	 * @return object[]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args  = array_merge(
			array(
				'source_id' => 0,
				'level'     => '',
				'limit'     => 100,
				'offset'    => 0,
			),
			$args
		);
		$where = array( '1=1' );
		$vals  = array();
		if ( $args['source_id'] ) {
			$where[] = 'source_id = %d';
			$vals[]  = (int) $args['source_id'];
		}
		if ( $args['level'] ) {
			$where[] = 'level = %s';
			$vals[]  = $args['level'];
		}
		$vals[] = (int) $args['limit'];
		$vals[] = (int) $args['offset'];
		$sql    = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		return $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore WordPress.DB
	}

	public static function count( array $args = array() ) {
		global $wpdb;
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['source_id'] ) ) {
			$where[] = 'source_id = %d';
			$vals[]  = (int) $args['source_id'];
		}
		if ( ! empty( $args['level'] ) ) {
			$where[] = 'level = %s';
			$vals[]  = $args['level'];
		}
		if ( ! empty( $args['since'] ) ) {
			$where[] = 'created_at >= %d';
			$vals[]  = (int) $args['since'];
		}
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		return (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB
	}

	public static function prune( $days ) {
		global $wpdb;
		if ( $days > 0 ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %d', time() - $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB
		}
	}
}
