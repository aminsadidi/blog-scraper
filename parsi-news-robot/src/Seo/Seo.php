<?php
/**
 * Keeps robot posts out of search engines while visitors still see them.
 *
 * - robots meta (Rank Math, Yoast, core wp_robots) + X-Robots-Tag header
 * - sitemaps: Rank Math (own meta + entry filter), Yoast, WordPress core
 * - Rank Math IndexNow submissions
 * - the site's own RSS feed (optional) and chosen category archives (optional)
 *
 * The effective policy (post override → source → global) is stored as `_pnr_noindex` on each post, together
 * with Rank Math's `rank_math_robots`, and re-synced in the background when a setting changes.
 * robots.txt is deliberately not touched: blocking crawling would hide the noindex tag from Google.
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Seo;

use ParsiNewsRobot\Data\Items;
use ParsiNewsRobot\Queue;
use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Sources;

defined( 'ABSPATH' ) || exit;

class Seo {

	const FLAG = '_pnr_noindex';

	public static function init() {
		add_filter( 'wp_robots', array( __CLASS__, 'wp_robots' ), 999 );
		add_filter( 'rank_math/frontend/robots', array( __CLASS__, 'rank_math_robots' ), 999 );
		add_filter( 'wpseo_robots_array', array( __CLASS__, 'yoast_robots' ), 999 );
		add_action( 'template_redirect', array( __CLASS__, 'robots_header' ), 5 );

		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'core_sitemap_args' ), 10, 2 );
		add_filter( 'rank_math/sitemap/entry', array( __CLASS__, 'rank_math_sitemap_entry' ), 10, 3 );
		add_filter( 'rank_math/instant_indexing/publish_url', array( __CLASS__, 'rank_math_indexnow' ), 10, 2 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( __CLASS__, 'yoast_sitemap_exclude' ) );

		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_from_site_feed' ) );
		add_action( 'wp_head', array( __CLASS__, 'frontend_style' ) );
	}

	public static function is_noindex( $post_id ) {
		return '1' === (string) get_post_meta( (int) $post_id, self::FLAG, true );
	}

	/**
	 * Whether the current request must stay out of the index.
	 */
	public static function current_is_noindex() {
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof \WP_Post ) {
				return false;
			}
			// Attachment pages of the robot's images follow their post.
			if ( 'attachment' === $post->post_type && $post->post_parent ) {
				return self::is_noindex( $post->post_parent );
			}
			return self::is_noindex( $post->ID );
		}
		$cats = array_map( 'intval', (array) Settings::get( 'noindex_categories' ) );
		return $cats && is_category( $cats );
	}

	public static function wp_robots( $robots ) {
		if ( self::current_is_noindex() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'], $robots['max-image-preview'] );
		}
		return $robots;
	}

	public static function rank_math_robots( $robots ) {
		if ( self::current_is_noindex() ) {
			$robots          = is_array( $robots ) ? $robots : array();
			$robots['index'] = 'noindex';
			if ( empty( $robots['follow'] ) ) {
				$robots['follow'] = 'follow';
			}
			unset( $robots['max-snippet'], $robots['max-video-preview'], $robots['max-image-preview'] );
		}
		return $robots;
	}

	public static function yoast_robots( $robots ) {
		if ( self::current_is_noindex() ) {
			$robots['index'] = 'noindex';
		}
		return $robots;
	}

	public static function robots_header() {
		if ( ! headers_sent() && self::current_is_noindex() ) {
			header( 'X-Robots-Tag: noindex, follow', false );
		}
	}

	public static function core_sitemap_args( $args, $post_type ) {
		if ( 'post' === $post_type ) {
			$args['meta_query']   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_query'][] = array(
				'key'     => self::FLAG,
				'compare' => 'NOT EXISTS',
			);
		}
		return $args;
	}

	public static function rank_math_sitemap_entry( $url, $type, $object ) {
		if ( 'post' === $type && is_object( $object ) && ! empty( $object->ID ) && isset( self::hidden_ids()[ (int) $object->ID ] ) ) {
			return false;
		}
		return $url;
	}

	public static function rank_math_indexnow( $url, $post ) {
		return ( $post instanceof \WP_Post && self::is_noindex( $post->ID ) ) ? false : $url;
	}

	public static function yoast_sitemap_exclude( $ids ) {
		return array_merge( (array) $ids, array_keys( self::hidden_ids() ) );
	}

	/**
	 * Ids of every post kept out of the index, loaded with one query per request (sitemaps only).
	 *
	 * @return array<int, true>
	 */
	private static function hidden_ids() {
		static $ids = null;
		if ( null === $ids ) {
			global $wpdb;
			$ids = array_fill_keys( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'", self::FLAG ) ) ), true ); // phpcs:ignore WordPress.DB
		}
		return $ids;
	}

	public static function exclude_from_site_feed( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_feed() || ! Settings::get( 'exclude_site_feed' ) ) {
			return;
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'key'     => Items::META,
			'compare' => 'NOT EXISTS',
		);
		$query->set( 'meta_query', $meta );
	}

	/**
	 * A few bytes of CSS for responsive video embeds and the source line — robot posts only.
	 */
	public static function frontend_style() {
		if ( ! is_singular( 'post' ) || ! get_post_meta( get_queried_object_id(), Items::META, true ) ) {
			return;
		}
		echo '<style id="pnr-style">.pnr-video{margin:1em 0}.pnr-video iframe{display:block;width:100%;height:auto;aspect-ratio:16/9;border:0}video{max-width:100%;height:auto}.pnr-source{font-size:.95em;margin:1.2em 0}.pnr-source-small{font-size:.8em;opacity:.8}.pnr-source-box{padding:.6em 1em;border-inline-start:3px solid currentColor;background:rgba(127,127,127,.08);border-radius:4px}</style>' . "\n";
	}

	/* ---------- Policy flag ---------- */

	/**
	 * Effective policy: post override → source → global.
	 */
	public static function compute( $post_id ) {
		$item = Items::get( $post_id );
		if ( $item && 'index' === $item->index_override ) {
			return false;
		}
		if ( $item && 'noindex' === $item->index_override ) {
			return true;
		}
		$source_id = $item ? (int) $item->source_id : (int) get_post_meta( $post_id, Items::META, true );
		$cfg       = $source_id && Sources::exists( $source_id ) ? Sources::config( $source_id ) : Settings::source_defaults();
		return Settings::noindex( $cfg );
	}

	/**
	 * Writes the flag and mirrors it into Rank Math's own robots meta (its sitemap and editor then agree).
	 */
	public static function apply( $post_id, $noindex ) {
		if ( $noindex ) {
			update_post_meta( $post_id, self::FLAG, '1' );
			update_post_meta( $post_id, 'rank_math_robots', array( 'noindex' ) );
		} else {
			delete_post_meta( $post_id, self::FLAG );
			$rm = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( is_array( $rm ) && in_array( 'noindex', $rm, true ) ) {
				delete_post_meta( $post_id, 'rank_math_robots' );
			}
		}
	}

	/**
	 * Meta for wp_insert_post, so the post is hidden from its very first save.
	 */
	public static function insert_meta( $noindex ) {
		return $noindex ? array(
			self::FLAG         => '1',
			'rank_math_robots' => array( 'noindex' ),
		) : array();
	}

	/**
	 * Recomputes the flag of every robot post in batches; reschedules itself when time runs out.
	 */
	public static function sync_all() {
		$start = time();
		$last  = (int) get_option( 'pnr_sync_cursor', 0 );
		do {
			$ids = Items::batch( $last, 200 );
			foreach ( $ids as $id ) {
				self::apply( $id, self::compute( $id ) );
				$last = $id;
			}
			if ( $ids && time() - $start > 40 ) {
				update_option( 'pnr_sync_cursor', $last, false );
				Queue::schedule_sync();
				return;
			}
		} while ( $ids );

		delete_option( 'pnr_sync_cursor' );
		self::flush_sitemaps();
	}

	public static function flush_sitemaps() {
		if ( class_exists( '\RankMath\Sitemap\Cache' ) && method_exists( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
		}
	}
}
