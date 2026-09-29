<?php
/**
 * Every feed entry the robot has looked at. Survives post deletion, so deleted news is not re-imported,
 * and doubles as the job payload store for queued items.
 *
 * Status: queued | imported | skipped | duplicate | failed.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Data;

defined( 'ABSPATH' ) || exit;

class Seen {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pnr_seen';
	}

	/**
	 * @return int Row id (0 when the hash already existed).
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$row = array_merge(
			array(
				'source_id'    => 0,
				'item_hash'    => '',
				'title_norm'   => '',
				'title_hash'   => '',
				'link'         => '',
				'feed_sig'     => '',
				'status'       => 'queued',
				'post_id'      => 0,
				'attempts'     => 0,
				'payload'      => null,
				'note'         => '',
				'scheduled_at' => 0,
				'created_at'   => time(),
			),
			$row
		);
		if ( is_array( $row['payload'] ) ) {
			$row['payload'] = wp_json_encode( $row['payload'] );
		}
		$row['title_norm'] = \ParsiNewsRobot\Support\Util::substr( (string) $row['title_norm'], 0, 250 );
		$row['note']       = \ParsiNewsRobot\Support\Util::db_safe( \ParsiNewsRobot\Support\Util::substr( (string) $row['note'], 0, 250 ), self::table(), 'note' );

		$ok = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return object|null
	 */
	public static function by_hash( $hash ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, source_id, status, post_id, feed_sig, created_at FROM ' . self::table() . ' WHERE item_hash = %s', $hash ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Atomically moves a queued entry to "processing"; only one runner can win it.
	 */
	public static function claim( $id ) {
		global $wpdb;
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'processing', scheduled_at = %d WHERE id = %d AND status = 'queued'", time(), (int) $id ) ); // phpcs:ignore WordPress.DB
		return 1 === (int) $done;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		if ( isset( $data['payload'] ) && is_array( $data['payload'] ) ) {
			$data['payload'] = wp_json_encode( $data['payload'] );
		}
		if ( isset( $data['note'] ) ) {
			$data['note'] = \ParsiNewsRobot\Support\Util::db_safe( \ParsiNewsRobot\Support\Util::substr( (string) $data['note'], 0, 250 ), self::table(), 'note' );
		}
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Titles seen recently (for similarity checks), newest first.
	 *
	 * @return object[] {id, title_norm, title_hash, status, post_id}
	 */
	public static function recent_titles( $since, $limit = 3000 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, title_norm, title_hash, status, post_id FROM ' . self::table() . " WHERE created_at >= %d AND status IN ('queued','processing','imported') ORDER BY id DESC LIMIT %d", (int) $since, (int) $limit ) ); // phpcs:ignore WordPress.DB
	}

	public static function by_title_hash( $hash, $since, $exclude_id = 0 ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, status, post_id FROM ' . self::table() . " WHERE title_hash = %s AND created_at >= %d AND id <> %d AND status IN ('queued','processing','imported') ORDER BY id ASC LIMIT 1", $hash, (int) $since, (int) $exclude_id ) ); // phpcs:ignore WordPress.DB
	}

	public static function counts_by_status( $since = 0 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' WHERE created_at >= %d GROUP BY status', (int) $since ) ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ $row->status ] = (int) $row->n;
		}
		return $out;
	}

	public static function queued_count( $source_id = 0 ) {
		global $wpdb;
		if ( $source_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('queued','processing') AND source_id = %d", (int) $source_id ) ); // phpcs:ignore WordPress.DB
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('queued','processing')" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Forgets old entries; the window must stay longer than any source's "max age" so nothing is re-imported.
	 */
	public static function prune( $days ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE created_at < %d AND status NOT IN ('queued','processing')", time() - max( 7, (int) $days ) * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB
	}

	public static function delete_source( $source_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE source_id = %d AND status IN ('queued','processing')", (int) $source_id ) ); // phpcs:ignore WordPress.DB
	}
}
