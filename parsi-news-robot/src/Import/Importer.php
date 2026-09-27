<?php
/**
 * Queue job handlers: import one queued entry, or apply an update to an already imported one.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Import;

use ParsiNewsRobot\Data\Items;
use ParsiNewsRobot\Data\Log;
use ParsiNewsRobot\Data\Seen;
use ParsiNewsRobot\Queue;
use ParsiNewsRobot\Sources;

defined( 'ABSPATH' ) || exit;

class Importer {

	const MAX_ATTEMPTS = 3;

	/**
	 * @return int|\WP_Error|null Post id, error, or null when there was nothing to do.
	 */
	public static function import( $seen_id ) {
		$row = Seen::get( $seen_id );
		if ( ! $row || 'queued' !== $row->status ) {
			return null;
		}
		$source_id = (int) $row->source_id;
		if ( ! Sources::exists( $source_id ) ) {
			Seen::update(
				$row->id,
				array(
					'status'  => 'failed',
					'note'    => 'منبع حذف شده است.',
					'payload' => null,
				)
			);
			return null;
		}

		$item = json_decode( (string) $row->payload, true );
		if ( ! is_array( $item ) ) {
			Seen::update(
				$row->id,
				array(
					'status' => 'failed',
					'note'   => 'اطلاعات خبر ناقص است.',
				)
			);
			return null;
		}

		// Another source may have published the same story since this one was queued.
		$similar = Dedupe::find_similar( $item['title'], $row->id );
		if ( $similar && (int) $similar->id < (int) $row->id ) {
			Seen::update(
				$row->id,
				array(
					'status'  => 'duplicate',
					'post_id' => (int) $similar->post_id,
					'note'    => 'مشابه خبری که قبلاً منتشر شد.',
					'payload' => null,
				)
			);
			return null;
		}

		self::extend_time_limit();
		$cfg      = Sources::config( $source_id );
		$prepared = Builder::build( $item, $cfg );

		if ( ! is_wp_error( $prepared ) ) {
			$post_id = Publisher::publish( $prepared, $source_id, $cfg, $row );
			if ( ! is_wp_error( $post_id ) ) {
				Seen::update(
					$row->id,
					array(
						'status'  => 'imported',
						'post_id' => $post_id,
						'note'    => '',
						'payload' => null,
					)
				);
				Sources::bump_total( $source_id );
				return $post_id;
			}
			$prepared = $post_id;
		}

		if ( 'pnr_skip' === $prepared->get_error_code() ) {
			Seen::update(
				$row->id,
				array(
					'status'  => 'skipped',
					'note'    => $prepared->get_error_message(),
					'payload' => null,
				)
			);
			Log::info( sprintf( 'رد شد: «%1$s» — %2$s', $item['title'], $prepared->get_error_message() ), $source_id );
			return $prepared;
		}

		$attempts = (int) $row->attempts + 1;
		if ( $attempts < self::MAX_ATTEMPTS ) {
			Seen::update(
				$row->id,
				array(
					'attempts' => $attempts,
					'note'     => $prepared->get_error_message(),
				)
			);
			Queue::schedule_import( $row->id, time() + 10 * MINUTE_IN_SECONDS * $attempts );
			Log::warning( sprintf( 'تلاش %1$d برای «%2$s» ناموفق بود؛ دوباره امتحان می‌شود: %3$s', $attempts, $item['title'], $prepared->get_error_message() ), $source_id );
		} else {
			Seen::update(
				$row->id,
				array(
					'status'   => 'failed',
					'attempts' => $attempts,
					'note'     => $prepared->get_error_message(),
					'payload'  => null,
				)
			);
			Log::error( sprintf( 'دریافت «%1$s» پس از %2$d تلاش ناموفق ماند: %3$s', $item['title'], $attempts, $prepared->get_error_message() ), $source_id );
		}
		return $prepared;
	}

	/**
	 * Re-builds an imported story whose feed entry changed (e.g. a live score) and updates the post.
	 */
	public static function update( $seen_id ) {
		$row = Seen::get( $seen_id );
		if ( ! $row || ! $row->post_id || ! $row->payload ) {
			return null;
		}
		$post_id = (int) $row->post_id;
		$item    = json_decode( (string) $row->payload, true );
		Seen::update( $row->id, array( 'payload' => null ) );

		$stored = Items::get( $post_id );
		if ( ! $stored || ! get_post( $post_id ) || ! is_array( $item ) ) {
			return null;
		}
		if ( Publisher::edited_by_human( $post_id ) ) {
			Log::info( sprintf( 'خبر «%s» در منبع تغییر کرد ولی چون دستی ویرایش شده، به‌روز نشد.', get_post_field( 'post_title', $post_id ) ), $row->source_id );
			return null;
		}

		self::extend_time_limit();
		$prepared = Builder::build( $item, Sources::config( (int) $row->source_id ) );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( $prepared['content_hash'] === $stored->content_hash ) {
			Items::update( $post_id, array( 'feed_sig' => $prepared['feed_sig'] ) );
			return null;
		}
		$result = Publisher::update_content( $post_id, $prepared );
		if ( true === $result ) {
			Log::success( sprintf( 'به‌روزرسانی شد: «%s»', $prepared['title'] ), $row->source_id );
		}
		return $result;
	}

	private static function extend_time_limit() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
}
