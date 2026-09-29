<?php
/**
 * Automatic deletion of old robot posts, redirects for their addresses, and housekeeping.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot;

use ParsiNewsRobot\Data\Items;
use ParsiNewsRobot\Data\Log;
use ParsiNewsRobot\Data\Placements;
use ParsiNewsRobot\Data\Redirects;
use ParsiNewsRobot\Data\Seen;
use ParsiNewsRobot\Import\Publisher;
use ParsiNewsRobot\Media\Media;

defined( 'ABSPATH' ) || exit;

class Cleanup {

	const BATCH = 50;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_404' ), 1 );
		add_action( 'deleted_post', array( __CLASS__, 'forget_post' ) );
	}

	/**
	 * Hourly job.
	 */
	public static function run() {
		$start    = time();
		$deleted  = 0;
		$settings = Settings::get();
		$sources  = Sources::all_ids();

		foreach ( $sources as $source_id ) {
			$days = Settings::delete_days( Sources::config( $source_id ) );
			if ( $days > 0 ) {
				$deleted += self::delete_expired( $source_id, time() - $days * DAY_IN_SECONDS, $start );
			}
		}
		// Posts whose source was removed follow the global setting.
		if ( (int) $settings['delete_after_days'] > 0 ) {
			$deleted += self::delete_expired( null, time() - (int) $settings['delete_after_days'] * DAY_IN_SECONDS, $start, $sources );
		}
		if ( $deleted ) {
			Log::info( sprintf( '%d خبر قدیمی حذف و ریدایرکت شد.', $deleted ) );
		}

		Log::prune( (int) $settings['log_days'] );
		Seen::prune( 60 );
		Placements::prune( 45 );
		Redirects::prune( (int) $settings['redirect_keep_days'] );
		self::requeue_stuck();
		update_option( 'pnr_last_cleanup', time(), false );
		return $deleted;
	}

	private static function delete_expired( $source_id, $cutoff, $start, array $exclude = array() ) {
		$count = 0;
		do {
			$ids = Items::expired( $source_id, $cutoff, self::BATCH, $exclude );
			foreach ( $ids as $post_id ) {
				if ( ! get_post( $post_id ) ) {
					// The post was removed outside the plugin; just forget it.
					Items::delete( $post_id );
					continue;
				}
				if ( Settings::get( 'protect_edited' ) && Publisher::edited_by_human( $post_id ) ) {
					Items::update( $post_id, array( 'keep_post' => 1 ) );
					continue;
				}
				if ( self::delete_post( $post_id ) ) {
					$count++;
				} else {
					Items::delete( $post_id );
				}
			}
		} while ( $ids && time() - $start < 45 );
		return $count;
	}

	/**
	 * Deletes a robot post: records a redirect for its address, removes its images, deletes it.
	 */
	public static function delete_post( $post_id ) {
		$post = get_post( $post_id );
		$item = Items::get( $post_id );
		if ( ! $post || ! $item ) {
			if ( $item ) {
				Items::delete( $post_id );
			}
			return false;
		}
		$mode = Settings::get( 'redirect_mode' );
		if ( 'publish' === $post->post_status && 'none' !== $mode ) {
			$url    = get_permalink( $post );
			$target = (int) $item->main_term;
			if ( 'category' === $mode && ( ! $target || ! term_exists( $target, 'category' ) ) ) {
				$mode = 'home';
			}
			Redirects::add( $url, $mode, 'category' === $mode ? $target : 0 );
		}
		if ( Settings::get( 'delete_attachments' ) ) {
			Media::delete_post_media( $post_id );
		}
		wp_delete_post( $post_id, true );
		return true;
	}

	/**
	 * Keeps the plugin's tables in step when a robot post is deleted by any means.
	 */
	public static function forget_post( $post_id ) {
		if ( Items::get( $post_id ) ) {
			Items::delete( $post_id );
		}
	}

	/**
	 * Sends visitors of a deleted robot post to its category (301), the home page (301), or answers 410.
	 */
	public static function handle_404() {
		if ( ! is_404() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$redirect = Redirects::find( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $redirect ) {
			return;
		}
		Redirects::hit( $redirect->id );

		if ( 'gone' === $redirect->target_type ) {
			status_header( 410 );
			nocache_headers();
			return;
		}
		$url = home_url( '/' );
		if ( 'category' === $redirect->target_type && $redirect->target_id ) {
			$link = get_term_link( (int) $redirect->target_id, 'category' );
			if ( ! is_wp_error( $link ) ) {
				$url = $link;
			}
		}
		wp_safe_redirect( $url, 301, 'Parsi News Robot' );
		exit;
	}

	/**
	 * Re-queues entries whose job was lost (e.g. queue table cleaned by another plugin).
	 */
	private static function requeue_stuck() {
		global $wpdb;
		// "processing" for over an hour means the runner died (e.g. PHP time limit): try again.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, status FROM ' . Seen::table() . " WHERE ( status = 'queued' AND scheduled_at < %d ) OR ( status = 'processing' AND scheduled_at < %d ) LIMIT 20", time() - 6 * HOUR_IN_SECONDS, time() - HOUR_IN_SECONDS ) ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $row ) {
			if ( 'queued' === $row->status && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( Queue::IMPORT, array( (int) $row->id ), Queue::GROUP ) ) {
				continue;
			}
			Seen::update(
				$row->id,
				array(
					'status'       => 'queued',
					'scheduled_at' => time(),
				)
			);
			Queue::schedule_import( (int) $row->id, time() );
		}
	}

	/**
	 * Deletes (with redirects) every post of a source — e.g. after a publisher asks for removal.
	 */
	public static function purge_source( $source_id ) {
		$count = 0;
		do {
			$ids = Items::post_ids_of_source( $source_id, 100 );
			foreach ( $ids as $post_id ) {
				if ( self::delete_post( $post_id ) ) {
					$count++;
				} else {
					Items::delete( $post_id );
				}
			}
		} while ( $ids );
		Seen::delete_source( $source_id );
		Log::warning( sprintf( 'همه خبرهای این منبع حذف شد (%d خبر).', $count ), $source_id );
		return $count;
	}
}
