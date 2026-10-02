<?php
/**
 * One row per robot post: where it came from and how it must be treated.
 * Replaces a dozen postmeta rows per post and keeps cleanup/quota queries indexed.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Data;

defined( 'ABSPATH' ) || exit;

class Items {

	/** Post meta that marks a robot post (kept so WP_Query and themes can filter cheaply). */
	const META = '_pnr_source_id';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pnr_items';
	}

	public static function insert( array $row ) {
		global $wpdb;
		$row = array_merge(
			array(
				'post_id'        => 0,
				'source_id'      => 0,
				'seen_id'        => 0,
				'source_url'     => '',
				'source_name'    => '',
				'feed_sig'       => '',
				'content_hash'   => '',
				'sketch'         => '',
				'main_term'      => 0,
				'has_video'      => 0,
				'image_count'    => 0,
				'keep_post'      => 0,
				'index_override' => '',
				'imported_at'    => time(),
				'updated_at'     => time(),
			),
			$row
		);
		$row['source_name'] = \ParsiNewsRobot\Support\Util::db_safe( \ParsiNewsRobot\Support\Util::substr( (string) $row['source_name'], 0, 190 ), self::table(), 'source_name' );
		$wpdb->replace( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( (int) $row['post_id'], 'pnr_items' );
	}

	public static function update( $post_id, array $data ) {
		global $wpdb;
		$wpdb->update( self::table(), $data, array( 'post_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( (int) $post_id, 'pnr_items' );
	}

	/**
	 * @return object|null
	 */
	/**
	 * Loads the rows of many posts with one query (e.g. a page of the admin post list).
	 */
	public static function prime( array $post_ids ) {
		global $wpdb;
		$ids = array();
		foreach ( array_map( 'intval', $post_ids ) as $id ) {
			$found = false;
			wp_cache_get( $id, 'pnr_items', false, $found );
			if ( $id && ! $found ) {
				$ids[] = $id;
			}
		}
		if ( ! $ids ) {
			return;
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE post_id IN (' . implode( ',', $ids ) . ')', OBJECT_K ); // phpcs:ignore WordPress.DB -- integers only.
		foreach ( $ids as $id ) {
			wp_cache_set( $id, isset( $rows[ $id ] ) ? $rows[ $id ] : 0, 'pnr_items' );
		}
	}

	public static function get( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return null;
		}
		$found = false;
		$row   = wp_cache_get( $post_id, 'pnr_items', false, $found );
		if ( $found ) {
			return $row ? $row : null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE post_id = %d', $post_id ) ); // phpcs:ignore WordPress.DB
		wp_cache_set( $post_id, $row ? $row : 0, 'pnr_items' );
		return $row ? $row : null;
	}

	/**
	 * @return object|null The row created for a seen entry (a previous, interrupted attempt).
	 */
	public static function by_seen( $seen_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE seen_id = %d LIMIT 1', (int) $seen_id ) ); // phpcs:ignore WordPress.DB
	}

	public static function delete( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( (int) $post_id, 'pnr_items' );
	}

	/**
	 * Posts older than $cutoff that may be auto-deleted.
	 *
	 * @param int|int[]|null $source_ids One id, several ids, or null for "not in $exclude".
	 * @return int[]
	 */
	public static function expired( $source_ids, $cutoff, $limit = 50, array $exclude = array() ) {
		global $wpdb;
		$sql  = 'SELECT post_id FROM ' . self::table() . ' WHERE keep_post = 0 AND imported_at < %d';
		$vals = array( (int) $cutoff );
		if ( null === $source_ids ) {
			if ( $exclude ) {
				$sql .= ' AND source_id NOT IN (' . implode( ',', array_map( 'intval', $exclude ) ) . ')';
			}
		} else {
			$sql .= ' AND source_id IN (' . implode( ',', array_map( 'intval', (array) $source_ids ) ) . ')';
		}
		$sql   .= ' ORDER BY imported_at ASC LIMIT %d';
		$vals[] = (int) $limit;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $vals ) ) ); // phpcs:ignore WordPress.DB
	}

	public static function count( $source_id = 0, $since = 0 ) {
		global $wpdb;
		$sql  = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE imported_at >= %d';
		$vals = array( (int) $since );
		if ( $source_id ) {
			$sql   .= ' AND source_id = %d';
			$vals[] = (int) $source_id;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return int[] post ids of a source.
	 */
	public static function post_ids_of_source( $source_id, $limit = 500 ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM ' . self::table() . ' WHERE source_id = %d LIMIT %d', (int) $source_id, (int) $limit ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return int[] post ids after $after (for batch jobs).
	 */
	public static function batch( $after, $limit = 200 ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM ' . self::table() . ' WHERE post_id > %d ORDER BY post_id ASC LIMIT %d', (int) $after, (int) $limit ) ) ); // phpcs:ignore WordPress.DB
	}
}
