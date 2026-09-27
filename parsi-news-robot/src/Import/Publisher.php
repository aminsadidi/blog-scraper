<?php
/**
 * Creates and updates robot posts.
 *
 * Posts are created as drafts, get their images and SEO flags, and are published last, so nothing
 * half-built (or indexable) is ever public. Robot posts keep no revisions and send no pingbacks.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Import;

use ParsiNewsRobot\Data\Items;
use ParsiNewsRobot\Data\Log;
use ParsiNewsRobot\Data\Placements;
use ParsiNewsRobot\Media\Media;
use ParsiNewsRobot\Seo\Seo;
use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Sources;
use ParsiNewsRobot\Taxonomy\Categorizer;

defined( 'ABSPATH' ) || exit;

class Publisher {

	public static function init() {
		add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'no_revisions' ), 10, 2 );
		add_action( 'pre_ping', array( __CLASS__, 'no_pings' ), 10, 3 );
	}

	public static function is_robot_post( $post_id ) {
		return (bool) get_post_meta( (int) $post_id, Items::META, true );
	}

	public static function no_revisions( $num, $post ) {
		return ( $post && self::is_robot_post( $post->ID ) ) ? 0 : $num;
	}

	public static function no_pings( &$post_links, &$pung, $post_id = 0 ) {
		if ( $post_id && self::is_robot_post( $post_id ) ) {
			$post_links = array();
		}
	}

	/**
	 * @param array  $p    Result of Builder::build().
	 * @param object $seen Row from the seen table.
	 * @return int|\WP_Error Post id.
	 */
	public static function publish( array $p, $source_id, array $cfg, $seen ) {
		$categories = Categorizer::assign( $cfg, $p['has_video'], $p['image_count'], $p['rules'] );
		$final      = Settings::post_status( $cfg );
		$noindex    = Settings::noindex( $cfg );
		$now        = time();
		$timestamp  = ( 'source' === Settings::get( 'date_mode' ) && $p['date'] > 0 && $p['date'] <= $now ) ? $p['date'] : $now;

		$comments = Settings::get( 'comment_status' );
		if ( ! in_array( $comments, array( 'open', 'closed' ), true ) ) {
			$comments = get_default_comment_status( 'post' );
		}

		$meta = array(
			Items::META                  => (int) $source_id,
			'rank_math_primary_category' => (int) $categories['primary'],
		) + Seo::insert_meta( $noindex );

		$postarr = array(
			'post_type'      => 'post',
			'post_status'    => 'draft',
			'post_title'     => $p['title'],
			'post_content'   => $p['content'],
			'post_excerpt'   => $p['excerpt'],
			'post_author'    => Settings::author( $cfg ),
			'post_category'  => $categories['ids'],
			'post_date'      => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) ),
			'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'comment_status' => $comments,
			'ping_status'    => 'closed',
			'meta_input'     => $meta,
		);

		$kses = self::disable_kses();
		try {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			Items::insert(
				array(
					'post_id'      => $post_id,
					'source_id'    => (int) $source_id,
					'seen_id'      => $seen ? (int) $seen->id : 0,
					'source_url'   => $p['link'],
					'source_name'  => Sources::name( $source_id, $cfg ),
					'feed_sig'     => $p['feed_sig'],
					'content_hash' => $p['content_hash'],
					'main_term'    => (int) $categories['primary'],
					'has_video'    => $p['has_video'] ? 1 : 0,
					'image_count'  => (int) $p['image_count'],
					'imported_at'  => $now,
					'updated_at'   => $now,
				)
			);

			$image_mode = Settings::image_mode( $cfg );
			if ( $p['featured'] ) {
				Media::set_featured( $p['featured'], $post_id, $image_mode, $p['title'], $p['page_url'] );
			}
			if ( 'local' === $image_mode && $p['images'] ) {
				$content = Media::localize_content_images( $p['content'], $post_id, (int) Settings::get( 'max_images' ), $p['title'], $p['page_url'] );
				if ( $content !== $p['content'] ) {
					wp_update_post(
						wp_slash(
							array(
								'ID'           => $post_id,
								'post_content' => $content,
							)
						)
					);
				}
			}
			if ( $cfg['import_tags'] && $p['tags'] ) {
				wp_set_post_tags( $post_id, array_slice( $p['tags'], 0, 10 ), true );
			}

			// wp_update_post (not wp_publish_post) so WordPress also generates the unique slug on publish.
			if ( 'draft' !== $final ) {
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => $final,
					)
				);
			}
		} finally {
			self::restore_kses( $kses );
		}

		foreach ( $categories['sections'] as $term_id ) {
			Placements::add( $post_id, $term_id, $source_id );
		}
		// WordPress queues pingbacks and enclosure checks on publish; robot posts need neither.
		delete_post_meta( $post_id, '_pingme' );
		delete_post_meta( $post_id, '_encloseme' );
		// The edit time we set ourselves; later edits by a person are detected against it.
		Items::update( $post_id, array( 'updated_at' => time() ) );

		$names = array();
		foreach ( $categories['ids'] as $term_id ) {
			$term    = get_term( $term_id, 'category' );
			$names[] = $term && ! is_wp_error( $term ) ? $term->name : $term_id;
		}
		Log::success( sprintf( 'منتشر شد: «%1$s» — دسته‌ها: %2$s', $p['title'], implode( '، ', $names ) ), $source_id );

		return (int) $post_id;
	}

	/**
	 * Applies a changed source story to an existing robot post (title, text, excerpt).
	 */
	public static function update_content( $post_id, array $p ) {
		$kses = self::disable_kses();
		try {
			$result = wp_update_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_title'   => $p['title'],
						'post_content' => $p['content'],
						'post_excerpt' => $p['excerpt'],
					)
				),
				true
			);
		} finally {
			self::restore_kses( $kses );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		Items::update(
			$post_id,
			array(
				'content_hash' => $p['content_hash'],
				'feed_sig'     => $p['feed_sig'],
				'has_video'    => $p['has_video'] ? 1 : 0,
				'image_count'  => (int) $p['image_count'],
				'updated_at'   => time(),
			)
		);
		return true;
	}

	/**
	 * Content was sanitised with our own whitelist (Cleaner); WordPress' kses would drop the video iframes
	 * when the import runs without a logged-in user.
	 */
	private static function disable_kses() {
		$active = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $active ) {
			kses_remove_filters();
		}
		return $active;
	}

	private static function restore_kses( $was_active ) {
		if ( $was_active ) {
			kses_init_filters();
		}
	}

	/**
	 * Whether a person edited the post after the robot last wrote it.
	 */
	public static function edited_by_human( $post_id ) {
		$item = Items::get( $post_id );
		$post = get_post( $post_id );
		if ( ! $item || ! $post ) {
			return false;
		}
		return strtotime( $post->post_modified_gmt . ' UTC' ) > (int) $item->updated_at + 120;
	}
}
